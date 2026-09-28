<?php

namespace App\Domain\Apps\Content;

use Illuminate\Support\Facades\Cache;

/**
 * Feed The Beast (api.feed-the-beast.com) — modpacki FTB z pełną listą plików
 * (adres, SHA-1, rozmiar, znacznik „tylko klient”). Bez klucza API.
 */
class Ftb
{
    public const NAME = 'Feed The Beast';

    private const API = 'https://api.feed-the-beast.com/v1/modpacks/public/modpack';

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(string $query, int $page, int $perPage = 20): array
    {
        $ids = Cache::remember('ftb:ids:'.md5($query), 600, function () use ($query) {
            $data = $query === ''
                ? ContentHttp::json(ContentHttp::client()->get(self::API.'/popular/installs/100'), self::NAME)
                : ContentHttp::json(ContentHttp::client()->get(self::API.'/search/50', ['term' => $query]), self::NAME);

            return array_values(array_map('intval', $data['packs'] ?? []));
        });

        $slice = array_slice($ids, max(0, $page - 1) * $perPage, $perPage);
        $items = [];
        foreach ($slice as $id) {
            try {
                $items[] = $this->summary($this->pack($id));
            } catch (ContentException) {
                // Paczka wycofana — pomijamy na liście.
            }
        }

        return ['items' => $items, 'total' => count($ids)];
    }

    /** @return array<string, mixed> */
    public function pack(int $id): array
    {
        return Cache::remember("ftb:pack:{$id}", 900, fn () => ContentHttp::json(
            ContentHttp::client()->get(self::API.'/'.$id), self::NAME,
        ));
    }

    /** @return array<string, mixed> */
    public function summary(array $p): array
    {
        $icon = collect($p['art'] ?? [])->firstWhere('type', 'square')['url'] ?? null;

        return [
            'source' => 'ftb',
            'id' => (string) $p['id'],
            'slug' => (string) ($p['slug'] ?? $p['id']),
            'name' => (string) ($p['name'] ?? ''),
            'summary' => (string) ($p['synopsis'] ?? ''),
            'icon' => $icon,
            'downloads' => (int) ($p['installs'] ?? 0),
            'author' => $p['authors'][0]['name'] ?? 'FTB',
            'url' => 'https://www.feed-the-beast.com/modpacks/'.$p['id'].'-'.($p['slug'] ?? ''),
        ];
    }

    /** @return list<array<string, mixed>> najnowsze pierwsze */
    public function versions(int $id): array
    {
        $versions = [];
        foreach (array_reverse($this->pack($id)['versions'] ?? []) as $v) {
            $targets = collect($v['targets'] ?? []);
            $loader = $targets->firstWhere('type', 'modloader');
            $versions[] = [
                'id' => (string) $v['id'],
                'project_id' => (string) $id,
                'name' => (string) $v['name'],
                'number' => (string) $v['name'],
                'game_versions' => array_filter([$targets->firstWhere('name', 'minecraft')['version'] ?? null]),
                'loaders' => array_filter([$loader['name'] ?? null]),
                'type' => (string) ($v['type'] ?? 'release'),
                'date' => isset($v['updated']) ? date(DATE_ATOM, (int) $v['updated']) : null,
                'files' => [],
                'dependencies' => [],
                'memory' => $v['specs']['recommended'] ?? null,
            ];
        }

        return $versions;
    }

    /** @return array<string, mixed> pełna wersja z listą plików */
    public function version(int $packId, int $versionId): array
    {
        return Cache::remember("ftb:version:{$packId}:{$versionId}", 900, fn () => ContentHttp::json(
            ContentHttp::client(timeout: 60)->get(self::API.'/'.$packId.'/'.$versionId), self::NAME,
        ));
    }
}
