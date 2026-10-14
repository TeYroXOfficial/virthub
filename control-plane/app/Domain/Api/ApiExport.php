<?php

namespace App\Domain\Api;

/** OpenAPI 3.0 i kolekcja Postman 2.1 z ApiCatalog (w bieżącym języku panelu). */
class ApiExport
{
    public static function baseUrl(): string
    {
        return rtrim(url('/api/v1'), '/');
    }

    /** @return array<string, mixed> */
    public static function openApi(): array
    {
        $groups = ApiCatalog::groups();
        $paths = [];
        foreach (ApiCatalog::endpoints() as $e) {
            $params = [];
            foreach ($e['path_params'] as $p) {
                $params[] = ['name' => $p['name'], 'in' => 'path', 'required' => true, 'description' => $p['description'], 'schema' => ['type' => $p['type']], 'example' => $p['example']];
            }
            foreach ($e['query'] as $p) {
                $params[] = array_filter(['name' => $p['name'], 'in' => 'query', 'required' => $p['required'], 'description' => $p['description'],
                    'schema' => self::schema($p), 'example' => $p['example']], fn ($v) => $v !== null);
            }
            $operation = [
                'operationId' => $e['id'],
                'tags' => [$groups[$e['group']]['name']],
                'summary' => $e['summary'],
                'description' => $e['description'].($e['scope'] ? "\n\n".__('Wymagany zakres tokenu: :scope', ['scope' => $e['scope']]) : ''),
                'security' => [['bearerAuth' => []]],
                'parameters' => $params,
                'responses' => [
                    (string) $e['status'] => ['description' => __('Sukces'), 'content' => ['application/json' => ['example' => $e['response']]]],
                ] + collect(ApiCatalog::errors())->mapWithKeys(fn ($d, $code) => [(string) $code => ['$ref' => '#/components/responses/E'.$code]])->all(),
            ];
            if ($e['body']) {
                $properties = [];
                foreach ($e['body'] as $p) {
                    $properties[$p['name']] = self::schema($p) + ['description' => $p['description'], 'example' => $p['example']];
                }
                $required = array_values(array_map(fn ($p) => $p['name'], array_filter($e['body'], fn ($p) => $p['required'])));
                // OpenAPI 3.0 nie dopuszcza pustej listy required.
                $schema = array_filter(['type' => 'object', 'properties' => $properties, 'required' => $required]);
                $operation['requestBody'] = ['required' => $required !== [], 'content' => ['application/json' => [
                    'schema' => $schema, 'example' => self::exampleBody($e),
                ]]];
            }
            $paths[$e['path']][strtolower($e['method'])] = $operation;
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => __(':brand API', ['brand' => config('virthub.brand')]),
                'version' => '1.0',
                'description' => self::introduction(),
            ],
            'servers' => [['url' => self::baseUrl()]],
            'tags' => array_values(array_map(fn ($g) => ['name' => $g['name'], 'description' => $g['description']], $groups)),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => __('Token z Moje konto → Bezpieczeństwo → Tokeny API.')]],
                'responses' => collect(ApiCatalog::errors())->mapWithKeys(fn ($d, $code) => ['E'.$code => [
                    'description' => $d,
                    'content' => ['application/json' => ['example' => $code === 422
                        ? ['message' => __('Pole hostname jest wymagane.'), 'errors' => ['hostname' => [__('Pole hostname jest wymagane.')]]]
                        : ['message' => $d]]],
                ]])->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function postman(): array
    {
        $groups = ApiCatalog::groups();
        $folders = [];
        foreach (ApiCatalog::endpoints() as $e) {
            $path = preg_replace('/\{(\w+)\}/', ':$1', $e['path']);
            $url = [
                'raw' => '{{baseUrl}}'.$path.self::queryString($e),
                'host' => ['{{baseUrl}}'],
                'path' => array_values(array_filter(explode('/', $path))),
                'variable' => array_map(fn ($p) => ['key' => $p['name'], 'value' => (string) $p['example'], 'description' => $p['description']], $e['path_params']),
                'query' => array_map(fn ($p) => ['key' => $p['name'], 'value' => is_scalar($p['example']) ? (string) $p['example'] : '', 'description' => $p['description'], 'disabled' => ! $p['required']], $e['query']),
            ];
            $request = [
                'method' => $e['method'],
                'header' => [['key' => 'Accept', 'value' => 'application/json']],
                'url' => $url,
                'description' => $e['description'].($e['scope'] ? "\n\n".__('Wymagany zakres tokenu: :scope', ['scope' => $e['scope']]) : ''),
            ];
            if ($e['body']) {
                $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
                $request['body'] = ['mode' => 'raw', 'raw' => json_encode(self::exampleBody($e), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'options' => ['raw' => ['language' => 'json']]];
            }
            $folders[$e['group']]['name'] = $groups[$e['group']]['name'];
            $folders[$e['group']]['description'] = $groups[$e['group']]['description'];
            $folders[$e['group']]['item'][] = [
                'name' => $e['summary'],
                'request' => $request,
                'response' => [[
                    'name' => __('Przykład'), 'code' => $e['status'], 'status' => (string) $e['status'],
                    'originalRequest' => $request, 'header' => [['key' => 'Content-Type', 'value' => 'application/json']],
                    '_postman_previewlanguage' => 'json',
                    'body' => json_encode($e['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]],
            ];
        }

        return [
            'info' => [
                'name' => __(':brand API', ['brand' => config('virthub.brand')]),
                'description' => self::introduction(),
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']]],
            'variable' => [
                ['key' => 'baseUrl', 'value' => self::baseUrl()],
                ['key' => 'token', 'value' => ''],
            ],
            'item' => array_values($folders),
        ];
    }

    public static function introduction(): string
    {
        return __('REST API panelu :brand. Uwierzytelnienie: nagłówek Authorization: Bearer <token>; token tworzysz w Moje konto → Bezpieczeństwo → Tokeny API i wybierasz mu zakres (servers:read, servers:write, admin, billing). Zapytania i odpowiedzi w JSON — wyślij nagłówek Accept: application/json. Operacje na maszynach są asynchroniczne: odpowiedź 202 z job_id, stan maszyny sprawdzasz w GET /servers/{server}. Limit: 60 zapytań na minutę na token (niektóre endpointy mają własny). Zdarzenia możesz też odbierać webhookami (Administracja → Webhooki).', ['brand' => config('virthub.brand')]);
    }

    /** @return array<string, mixed> */
    public static function exampleBody(array $e): array
    {
        $out = [];
        foreach ($e['body'] as $p) {
            if ($p['required'] || $p['example'] !== null) {
                $out[$p['name']] = $p['example'];
            }
        }

        return $out;
    }

    private static function queryString(array $e): string
    {
        $parts = array_map(fn ($p) => $p['name'].'='.rawurlencode(is_scalar($p['example']) ? (string) $p['example'] : ''), array_filter($e['query'], fn ($p) => $p['required']));

        return $parts ? '?'.implode('&', $parts) : '';
    }

    private static function schema(array $p): array
    {
        $schema = ['type' => $p['type']];
        if ($p['type'] === 'array') {
            $schema['items'] = ['type' => 'string'];
        }
        if (isset($p['enum'])) {
            $schema['enum'] = $p['enum'];
        }

        return $schema;
    }
}
