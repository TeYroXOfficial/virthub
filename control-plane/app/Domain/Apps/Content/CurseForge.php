<?php

namespace App\Domain\Apps\Content;

use Illuminate\Support\Facades\Cache;

/**
 * CurseForge (api.curseforge.com) — wymaga klucza API (VIRTHUB_CURSEFORGE_API_KEY,
 * darmowy z console.curseforge.com). Bez klucza źródło jest ukryte.
 */
class CurseForge
{
    public const NAME = 'CurseForge';

    private const API = 'https://api.curseforge.com/v1';

    public const GAME = 432;

    public const CLASS_IDS = ['modpack' => 4471, 'mod' => 6, 'plugin' => 5];

    public const LOADER_TYPES = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6];

    public static function enabled(): bool
    {
        return filled(config('virthub.curseforge_api_key'));
    }

    private function client()
    {
        return ContentHttp::client(['x-api-key' => (string) config('virthub.curseforge_api_key')]);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(string $kind, string $query, ?string $gameVersion, ?string $loader, int $page, int $perPage = 20): array
    {
        $params = array_filter([
            'gameId' => self::GAME,
            'classId' => self::CLASS_IDS[$kind],
            'searchFilter' => $query !== '' ? $query : null,
            'gameVersion' => $gameVersion,
            'modLoaderType' => $loader ? (self::LOADER_TYPES[$loader] ?? null) : null,
            'sortField' => 2,
            'sortOrder' => 'desc',
            'index' => max(0, $page - 1) * $perPage,
            'pageSize' => $perPage,
        ], fn ($v) => $v !== null);

        $data = Cache::remember('curseforge:search:'.md5(json_encode($params)), 600, fn () => ContentHttp::json(
            $this->client()->get(self::API.'/mods/search', $params), self::NAME,
        ));

        return [
            'items' => array_map(fn ($m) => $this->project($m), $data['data'] ?? []),
            'total' => min(10000, (int) ($data['pagination']['totalCount'] ?? 0)),
        ];
    }

    /** @return array<string, mixed> */
    public function project(array|int|string $mod): array
    {
        if (! is_array($mod)) {
            $mod = Cache::remember("curseforge:mod:{$mod}", 600, fn () => ContentHttp::json(
                $this->client()->get(self::API.'/mods/'.(int) $mod), self::NAME,
            )['data']);
        }

        return [
            'source' => 'curseforge',
            'id' => (string) $mod['id'],
            'slug' => (string) ($mod['slug'] ?? $mod['id']),
            'name' => (string) ($mod['name'] ?? ''),
            'summary' => (string) ($mod['summary'] ?? ''),
            'icon' => $mod['logo']['thumbnailUrl'] ?? null,
            'downloads' => (int) ($mod['downloadCount'] ?? 0),
            'author' => $mod['authors'][0]['name'] ?? null,
            'url' => $mod['links']['websiteUrl'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function versions(string $modId, ?string $gameVersion, ?string $loader): array
    {
        $params = array_filter([
            'gameVersion' => $gameVersion,
            'modLoaderType' => $loader ? (self::LOADER_TYPES[$loader] ?? null) : null,
            'pageSize' => 30,
        ], fn ($v) => $v !== null);
        $data = Cache::remember("curseforge:files:{$modId}:".md5(json_encode($params)), 300, fn () => ContentHttp::json(
            $this->client()->get(self::API.'/mods/'.(int) $modId.'/files', $params), self::NAME,
        ));

        return array_map(fn ($f) => $this->file($f), $data['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function fileById(string $modId, string $fileId): array
    {
        return $this->file(ContentHttp::json(
            $this->client()->get(self::API.'/mods/'.(int) $modId.'/files/'.(int) $fileId), self::NAME,
        )['data']);
    }

    /**
     * Wiele plików naraz (lista modów z manifestu modpacka).
     *
     * @param  list<int>  $fileIds
     * @return array<int, array<string, mixed>> indeks: id pliku
     */
    public function filesByIds(array $fileIds): array
    {
        $out = [];
        foreach (array_chunk($fileIds, 500) as $chunk) {
            $data = ContentHttp::json($this->client()->post(self::API.'/mods/files', ['fileIds' => $chunk]), self::NAME);
            foreach ($data['data'] ?? [] as $f) {
                $out[(int) $f['id']] = $this->file($f);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function file(array $f): array
    {
        $sha1 = collect($f['hashes'] ?? [])->firstWhere('algo', 1)['value'] ?? null;
        $gameVersions = array_values(array_filter($f['gameVersions'] ?? [], fn ($v) => preg_match('/^\d+\.\d+/', $v)));
        $loaders = array_values(array_map('strtolower', array_filter($f['gameVersions'] ?? [], fn ($v) => in_array(strtolower($v), ['forge', 'fabric', 'quilt', 'neoforge', 'bukkit', 'spigot', 'paper'], true))));

        return [
            'id' => (string) $f['id'],
            'project_id' => (string) ($f['modId'] ?? ''),
            'name' => (string) ($f['displayName'] ?? $f['fileName'] ?? ''),
            'number' => (string) ($f['displayName'] ?? ''),
            'game_versions' => $gameVersions,
            'loaders' => $loaders,
            'type' => [1 => 'release', 2 => 'beta', 3 => 'alpha'][$f['releaseType'] ?? 1] ?? 'release',
            'date' => $f['fileDate'] ?? null,
            'files' => [[
                'url' => $f['downloadUrl'] ?? null,
                'filename' => (string) ($f['fileName'] ?? ''),
                'size' => (int) ($f['fileLength'] ?? 0),
                'sha1' => is_string($sha1) ? strtolower($sha1) : null,
            ]],
            'dependencies' => array_values(array_map(fn ($d) => [
                'project_id' => (string) $d['modId'],
                'version_id' => null,
                'required' => true,
            ], array_filter($f['dependencies'] ?? [], fn ($d) => ($d['relationType'] ?? 0) === 3))),
            'server_pack_file_id' => $f['serverPackFileId'] ?? null,
        ];
    }
}
