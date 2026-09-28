<?php

namespace App\Domain\Apps\Content;

/** Modrinth (api.modrinth.com/v2) — modpacki (.mrpack), mody i pluginy. Bez klucza API. */
class Modrinth
{
    public const NAME = 'Modrinth';

    private const API = 'https://api.modrinth.com/v2';

    /** Listy wersji modpacków bywają ogromne (setki wydań) — trzymamy najnowsze. */
    private const MAX_VERSIONS = 80;

    /**
     * @param  'modpack'|'plugin'|'mod'  $kind
     * @param  list<string>  $loaders
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(string $kind, string $query, ?string $gameVersion, array $loaders, int $page, int $perPage = 20): array
    {
        // Tylko to, co działa na serwerze — bez paczek i modów wyłącznie klienckich.
        $serverSide = ['server_side:required', 'server_side:optional'];
        if ($kind !== 'modpack') {
            $serverSide[] = 'server_side:unknown'; // starsze pluginy nie mają tego pola
        }
        $facets = [["project_type:{$kind}"], $serverSide];
        if ($gameVersion) {
            $facets[] = ["versions:{$gameVersion}"];
        }
        if ($loaders !== []) {
            $facets[] = array_map(fn ($l) => "categories:{$l}", $loaders);
        }
        $params = [
            'query' => $query,
            'facets' => json_encode($facets),
            'index' => $query === '' ? 'downloads' : 'relevance',
            'offset' => max(0, $page - 1) * $perPage,
            'limit' => $perPage,
        ];

        return ContentHttp::cached('modrinth:search:'.md5(json_encode($params)), ContentHttp::LISTS, function () use ($params, $kind) {
            $data = ContentHttp::json(ContentHttp::client()->get(self::API.'/search', $params), self::NAME);

            return [
                'items' => array_map(fn ($h) => [
                    'source' => 'modrinth',
                    'id' => (string) $h['project_id'],
                    'slug' => (string) ($h['slug'] ?? $h['project_id']),
                    'name' => (string) ($h['title'] ?? ''),
                    'summary' => mb_substr((string) ($h['description'] ?? ''), 0, 300),
                    'icon' => $h['icon_url'] ?? null,
                    'downloads' => (int) ($h['downloads'] ?? 0),
                    'author' => $h['author'] ?? null,
                    'url' => 'https://modrinth.com/'.$kind.'/'.($h['slug'] ?? $h['project_id']),
                ], $data['hits'] ?? []),
                'total' => (int) ($data['total_hits'] ?? 0),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function project(string $id): array
    {
        return ContentHttp::cached("modrinth:project:v2:{$id}", ContentHttp::DETAILS, function () use ($id) {
            $p = ContentHttp::json(ContentHttp::client()->get(self::API.'/project/'.rawurlencode($id)), self::NAME);

            return [
                'source' => 'modrinth',
                'id' => (string) $p['id'],
                'slug' => (string) ($p['slug'] ?? $p['id']),
                'name' => (string) ($p['title'] ?? ''),
                'summary' => mb_substr((string) ($p['description'] ?? ''), 0, 500),
                'icon' => $p['icon_url'] ?? null,
                'downloads' => (int) ($p['downloads'] ?? 0),
                'author' => null,
                'url' => 'https://modrinth.com/project/'.($p['slug'] ?? $p['id']),
                'type' => $p['project_type'] ?? null,
                'server_side' => $p['server_side'] ?? null,
            ];
        });
    }

    /**
     * Wersje projektu — z filtrem zgodności po stronie Modrinth.
     *
     * @param  list<string>  $loaders
     * @param  list<string>  $gameVersions
     * @return list<array<string, mixed>>
     */
    public function versions(string $projectId, array $loaders = [], array $gameVersions = []): array
    {
        $params = array_filter([
            'loaders' => $loaders ? json_encode(array_values($loaders)) : null,
            'game_versions' => $gameVersions ? json_encode(array_values($gameVersions)) : null,
            'include_changelog' => 'false',
        ]);

        return ContentHttp::cached('modrinth:versions:'.$projectId.':'.md5(json_encode($params)), ContentHttp::LISTS, function () use ($projectId, $params) {
            $data = ContentHttp::json(ContentHttp::client()->get(self::API.'/project/'.rawurlencode($projectId).'/version', $params), self::NAME);

            return array_map(fn ($v) => $this->version($v), array_slice($data, 0, self::MAX_VERSIONS));
        });
    }

    /** @return array<string, mixed> */
    public function versionById(string $versionId): array
    {
        return ContentHttp::cached("modrinth:version:{$versionId}", ContentHttp::IMMUTABLE, fn () => $this->version(
            ContentHttp::json(ContentHttp::client()->get(self::API.'/version/'.rawurlencode($versionId)), self::NAME),
        ));
    }

    /** @return array<string, mixed> */
    private function version(array $v): array
    {
        $files = $v['files'] ?? [];
        usort($files, fn ($a, $b) => ($b['primary'] ?? false) <=> ($a['primary'] ?? false));

        return [
            'id' => (string) $v['id'],
            'project_id' => (string) ($v['project_id'] ?? ''),
            'name' => (string) ($v['name'] ?? $v['version_number'] ?? ''),
            'number' => (string) ($v['version_number'] ?? ''),
            'game_versions' => array_values($v['game_versions'] ?? []),
            'loaders' => array_values($v['loaders'] ?? []),
            'type' => (string) ($v['version_type'] ?? 'release'),
            'date' => $v['date_published'] ?? null,
            // Tylko plik główny (i .mrpack) — dodatkowe pliki (źródła, javadoc) są zbędne.
            'files' => array_map(fn ($f) => [
                'url' => (string) $f['url'],
                'filename' => (string) $f['filename'],
                'size' => (int) ($f['size'] ?? 0),
                'sha1' => $f['hashes']['sha1'] ?? null,
                'sha512' => $f['hashes']['sha512'] ?? null,
            ], array_slice($files, 0, 2)),
            'dependencies' => array_values(array_map(fn ($d) => [
                'project_id' => $d['project_id'] ?? null,
                'version_id' => $d['version_id'] ?? null,
                'required' => true,
            ], array_filter($v['dependencies'] ?? [], fn ($d) => ($d['dependency_type'] ?? '') === 'required'))),
        ];
    }
}
