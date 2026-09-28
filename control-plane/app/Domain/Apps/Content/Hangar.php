<?php

namespace App\Domain\Apps\Content;

/** Hangar (hangar.papermc.io) — oficjalne repozytorium pluginów Paper. Bez klucza API. */
class Hangar
{
    public const NAME = 'Hangar';

    private const API = 'https://hangar.papermc.io/api/v1';

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(string $query, ?string $gameVersion, int $page, int $perPage = 20): array
    {
        $params = array_filter([
            'q' => $query !== '' ? $query : null,
            'sort' => $query === '' ? '-downloads' : null,
            'platform' => 'PAPER',
            'version' => $gameVersion,
            'limit' => $perPage,
            'offset' => max(0, $page - 1) * $perPage,
        ], fn ($v) => $v !== null);

        return ContentHttp::cached('hangar:search:'.md5(json_encode($params)), ContentHttp::LISTS, function () use ($params) {
            $data = ContentHttp::json(ContentHttp::client()->get(self::API.'/projects', $params), self::NAME);

            return [
                'items' => array_map(fn ($p) => $this->project($p), $data['result'] ?? []),
                'total' => (int) ($data['pagination']['count'] ?? 0),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function projectBySlug(string $slug): array
    {
        return ContentHttp::cached("hangar:project:{$slug}", ContentHttp::DETAILS, fn () => $this->project(ContentHttp::json(
            ContentHttp::client()->get(self::API.'/projects/'.rawurlencode($slug)), self::NAME,
        )));
    }

    /** @return array<string, mixed> */
    private function project(array $p): array
    {
        $slug = (string) ($p['namespace']['slug'] ?? $p['name'] ?? '');

        return [
            'source' => 'hangar',
            'id' => $slug,
            'slug' => $slug,
            'name' => (string) ($p['name'] ?? $slug),
            'summary' => mb_substr((string) ($p['description'] ?? ''), 0, 300),
            'icon' => $p['avatarUrl'] ?? null,
            'downloads' => (int) ($p['stats']['downloads'] ?? 0),
            'author' => $p['namespace']['owner'] ?? null,
            'url' => 'https://hangar.papermc.io/'.($p['namespace']['owner'] ?? '').'/'.$slug,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function versions(string $slug, ?string $gameVersion): array
    {
        $params = array_filter(['platform' => 'PAPER', 'platformVersion' => $gameVersion, 'limit' => 25], fn ($v) => $v !== null);

        return ContentHttp::cached("hangar:versions:{$slug}:".md5(json_encode($params)), ContentHttp::LISTS, function () use ($slug, $params) {
            $data = ContentHttp::json(ContentHttp::client()->get(self::API.'/projects/'.rawurlencode($slug).'/versions', $params), self::NAME);

            $versions = [];
            foreach ($data['result'] ?? [] as $v) {
                $download = $v['downloads']['PAPER'] ?? null;
                // Pluginy hostowane poza Hangarem (externalUrl) nie mają sumy — pomijamy.
                if (! is_array($download) || empty($download['downloadUrl']) || empty($download['fileInfo'])) {
                    continue;
                }
                $channel = strtolower((string) ($v['channel']['name'] ?? 'release'));
                $versions[] = [
                    'id' => (string) $v['name'],
                    'project_id' => $slug,
                    'name' => (string) $v['name'],
                    'number' => (string) $v['name'],
                    'game_versions' => array_values($v['platformDependencies']['PAPER'] ?? []),
                    'loaders' => ['paper'],
                    'type' => $channel === 'release' ? 'release' : ($channel === 'snapshot' ? 'alpha' : 'beta'),
                    'date' => $v['createdAt'] ?? null,
                    'files' => [[
                        'url' => (string) $download['downloadUrl'],
                        'filename' => (string) $download['fileInfo']['name'],
                        'size' => (int) ($download['fileInfo']['sizeBytes'] ?? 0),
                        'sha256' => $download['fileInfo']['sha256Hash'] ?? null,
                    ]],
                    'dependencies' => [],
                ];
            }

            return $versions;
        });
    }
}
