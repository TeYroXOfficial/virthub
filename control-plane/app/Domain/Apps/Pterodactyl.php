<?php

namespace App\Domain\Apps;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Klient API panelu Pterodactyl — do migracji serwerów.
 *
 * Klucz aplikacji (ptla_) daje listę serwerów, właścicieli i eggi; klucz
 * klienta konta administratora (ptlc_) — dostęp do plików każdego serwera
 * (spakowanie i link do pobrania z Wings).
 */
class Pterodactyl
{
    public function __construct(
        private readonly string $url,
        private readonly string $applicationKey,
        private readonly string $clientKey,
    ) {}

    public static function normalizeUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    // --- API aplikacji ---------------------------------------------------------------

    /** @return list<array<string, mixed>> serwery z eggiem, właścicielem i portami */
    public function servers(): array
    {
        $servers = [];
        $page = 1;
        do {
            $data = $this->get($this->app(), '/api/application/servers', [
                'include' => 'egg,user,allocations', 'per_page' => 100, 'page' => $page,
            ]);
            foreach ($data['data'] ?? [] as $row) {
                $servers[] = $row['attributes'] ?? [];
            }
            $pages = (int) ($data['meta']['pagination']['total_pages'] ?? 1);
        } while (++$page <= $pages && $page <= 50);

        return $servers;
    }

    /**
     * Egg w formacie eksportu (PTDL_v2) — tak, jak przyjmuje go EggImporter.
     *
     * @return array<string, mixed>
     */
    public function egg(int $nest, int $egg): array
    {
        $attributes = $this->get($this->app(), "/api/application/nests/{$nest}/eggs/{$egg}", ['include' => 'variables'])['attributes'] ?? [];
        $images = $attributes['docker_images'] ?? null;
        if (! is_array($images) || $images === []) {
            $images = array_filter([(string) ($attributes['docker_image'] ?? '')]);
        }
        $config = $attributes['config'] ?? [];
        $script = $attributes['script'] ?? [];

        return [
            'meta' => ['version' => 'PTDL_v2'],
            'uuid' => $attributes['uuid'] ?? null,
            'name' => $attributes['name'] ?? '',
            'author' => $attributes['author'] ?? null,
            'description' => $attributes['description'] ?? null,
            'docker_images' => $images,
            'startup' => $attributes['startup'] ?? '',
            'config' => [
                'files' => is_string($config['files'] ?? null) ? $config['files'] : json_encode($config['files'] ?? new \stdClass),
                'startup' => is_string($config['startup'] ?? null) ? $config['startup'] : json_encode($config['startup'] ?? new \stdClass),
                'stop' => $config['stop'] ?? '^C',
            ],
            'scripts' => ['installation' => [
                'script' => $script['install'] ?? null,
                'container' => $script['container'] ?? null,
                'entrypoint' => $script['entry'] ?? null,
            ]],
            'variables' => array_map(fn ($v) => $v['attributes'] ?? [], $attributes['relationships']['variables']['data'] ?? []),
        ];
    }

    // --- API klienta (pliki serwera) --------------------------------------------------

    public function stop(string $identifier): void
    {
        $this->client()->post($this->url."/api/client/servers/{$identifier}/power", ['signal' => 'stop']);
    }

    /**
     * Pakuje wszystkie pliki serwera do jednego archiwum w katalogu serwera.
     * Zwraca nazwę archiwum (do pobrania i późniejszego usunięcia).
     */
    public function compressAll(string $identifier): string
    {
        $list = $this->get($this->client(), "/api/client/servers/{$identifier}/files/list", ['directory' => '/']);
        $names = array_values(array_filter(array_map(fn ($f) => $f['attributes']['name'] ?? null, $list['data'] ?? [])));
        if ($names === []) {
            throw new \RuntimeException(__('Serwer w Pterodactylu nie ma żadnych plików.'));
        }
        // Pakowanie dużego serwera trwa — Wings odpowiada dopiero po zakończeniu.
        $response = $this->client()->timeout(1800)->post($this->url."/api/client/servers/{$identifier}/files/compress", [
            'root' => '/', 'files' => $names,
        ]);
        $name = $this->json($response)['attributes']['name'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new \RuntimeException(__('Pterodactyl nie zwrócił nazwy archiwum.'));
        }

        return $name;
    }

    /** Jednorazowy link do pobrania pliku prosto z Wings (ważny kilkanaście minut). */
    public function downloadUrl(string $identifier, string $file): string
    {
        $url = $this->get($this->client(), "/api/client/servers/{$identifier}/files/download", ['file' => '/'.ltrim($file, '/')])['attributes']['url'] ?? null;
        if (! is_string($url) || ! preg_match('#^https?://#', $url)) {
            throw new \RuntimeException(__('Pterodactyl nie zwrócił linku do pobrania plików.'));
        }

        return $url;
    }

    public function deleteFile(string $identifier, string $file): void
    {
        $this->client()->post($this->url."/api/client/servers/{$identifier}/files/delete", ['root' => '/', 'files' => [$file]]);
    }

    // --- transport ---------------------------------------------------------------------

    private function app(): PendingRequest
    {
        return $this->request($this->applicationKey);
    }

    private function client(): PendingRequest
    {
        return $this->request($this->clientKey);
    }

    private function request(string $key): PendingRequest
    {
        return Http::withToken($key)->acceptJson()->asJson()->timeout(60)
            ->withHeaders(['User-Agent' => 'VirtHub-Migration']);
    }

    /** @return array<string, mixed> */
    private function get(PendingRequest $request, string $path, array $query = []): array
    {
        try {
            return $this->json($request->get($this->url.$path, $query));
        } catch (ConnectionException $e) {
            throw new \RuntimeException(__('Nie można połączyć się z panelem Pterodactyl: :error', ['error' => $e->getMessage()]), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private function json(\Illuminate\Http\Client\Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new \RuntimeException(__('Pterodactyl odrzucił klucz API (HTTP :status) — sprawdź klucz i jego uprawnienia.', ['status' => $response->status()]));
        }
        if (! $response->successful()) {
            $detail = $response->json('errors.0.detail') ?? mb_substr($response->body(), 0, 200);
            throw new \RuntimeException(__('Pterodactyl odpowiedział HTTP :status: :detail', ['status' => $response->status(), 'detail' => $detail]));
        }

        return (array) $response->json();
    }
}
