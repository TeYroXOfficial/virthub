<?php

namespace App\Domain\Apps\Content;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;

/**
 * Feed The Beast (api.feed-the-beast.com) — modpacki FTB z pełną listą plików
 * (adres, SHA-1, rozmiar, znacznik „tylko klient”). Bez klucza API.
 *
 * Wyszukiwarka FTB zwraca same identyfikatory, więc szczegóły paczek
 * pobieramy równolegle (Http::pool) i trzymamy w cache każdą osobno.
 */
class Ftb
{
    public const NAME = 'Feed The Beast';

    private const API = 'https://api.feed-the-beast.com/v1/modpacks/public/modpack';

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(string $query, int $page, int $perPage = 20): array
    {
        $ids = ContentHttp::cached('ftb:ids:'.md5($query), ContentHttp::LISTS, function () use ($query) {
            $data = $query === ''
                ? ContentHttp::json(ContentHttp::client()->get(self::API.'/popular/installs/100'), self::NAME)
                : ContentHttp::json(ContentHttp::client()->get(self::API.'/search/50', ['term' => $query]), self::NAME);

            return array_values(array_map('intval', $data['packs'] ?? []));
        });

        $slice = array_slice($ids, max(0, $page - 1) * $perPage, $perPage);
        $this->prefetch($slice);

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

    /** Brakujące w cache paczki — naraz, zamiast jednej po drugiej. */
    private function prefetch(array $ids): void
    {
        $missing = array_values(array_filter($ids, fn ($id) => ! Cache::has('content:ftb:pack:'.$id)));
        if ($missing === []) {
            return;
        }
        $responses = ContentHttp::client()->pool(fn (Pool $pool) => array_map(
            fn ($id) => $pool->as((string) $id)->withHeaders(['User-Agent' => ContentHttp::USER_AGENT])->acceptJson()->timeout(15)->get(self::API.'/'.$id),
            $missing,
        ));
        foreach ($missing as $id) {
            $response = $responses[(string) $id] ?? null;
            if ($response instanceof \Illuminate\Http\Client\Response && $response->successful() && is_array($response->json())) {
                Cache::flexible('content:ftb:pack:'.$id, ContentHttp::DETAILS, fn () => $this->trim($response->json()));
            }
        }
    }

    /** @return array<string, mixed> przycięta paczka: opis + lista wersji */
    public function pack(int $id): array
    {
        return ContentHttp::cached("ftb:pack:{$id}", ContentHttp::DETAILS, fn () => $this->trim(ContentHttp::json(
            ContentHttp::client()->get(self::API.'/'.$id), self::NAME,
        )));
    }

    /** Bez długiego opisu w Markdown i grafik — tylko to, co pokazuje panel. */
    private function trim(array $p): array
    {
        return [
            'id' => $p['id'] ?? null,
            'slug' => $p['slug'] ?? null,
            'name' => $p['name'] ?? '',
            'synopsis' => mb_substr((string) ($p['synopsis'] ?? ''), 0, 300),
            'installs' => $p['installs'] ?? 0,
            'authors' => array_slice(array_map(fn ($a) => ['name' => $a['name'] ?? null], $p['authors'] ?? []), 0, 1),
            'art' => array_values(array_map(fn ($a) => ['type' => $a['type'] ?? null, 'url' => $a['url'] ?? null],
                array_filter($p['art'] ?? [], fn ($a) => ($a['type'] ?? '') === 'square'))),
            'versions' => array_map(fn ($v) => [
                'id' => $v['id'], 'name' => $v['name'] ?? '', 'type' => $v['type'] ?? 'release', 'updated' => $v['updated'] ?? null,
                'targets' => $v['targets'] ?? [], 'specs' => ['recommended' => $v['specs']['recommended'] ?? null],
            ], $p['versions'] ?? []),
        ];
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
                'game_versions' => array_values(array_filter([$targets->firstWhere('name', 'minecraft')['version'] ?? null])),
                'loaders' => array_values(array_filter([$loader['name'] ?? null])),
                'type' => (string) ($v['type'] ?? 'release'),
                'date' => isset($v['updated']) ? date(DATE_ATOM, (int) $v['updated']) : null,
                'files' => [],
                'dependencies' => [],
                'memory' => $v['specs']['recommended'] ?? null,
            ];
        }

        return $versions;
    }

    /** @return array<string, mixed> pełna wersja z listą plików (potrzebna tylko przy instalacji) */
    public function version(int $packId, int $versionId): array
    {
        return ContentHttp::cached("ftb:version:{$packId}:{$versionId}", ContentHttp::LISTS, function () use ($packId, $versionId) {
            $v = ContentHttp::json(ContentHttp::client(timeout: 60)->get(self::API.'/'.$packId.'/'.$versionId), self::NAME);
            $v['files'] = array_map(fn ($f) => array_intersect_key($f, array_flip(
                ['path', 'name', 'url', 'sha1', 'size', 'clientonly', 'serveronly', 'curseforge'],
            )), $v['files'] ?? []);
            unset($v['changelog']);

            return $v;
        });
    }
}
