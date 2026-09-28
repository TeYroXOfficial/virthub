<?php

namespace App\Domain\Apps\Content;

use Illuminate\Support\Str;

/**
 * Modpack z serwisu → plan instalacji: wersja gry, loader, Java i lista
 * kroków dla agenta (pobrania z sumami, rozpakowanie overrides).
 *
 * Pliki tylko dla klienta (shadery, minimapy klienckie…) są pomijane tam,
 * gdzie serwis to oznacza (Modrinth env.server, FTB clientonly).
 */
class ModpackResolver
{
    /** Tylko gdy serwis nie obsługuje Range i trzeba pobrać całe archiwum. */
    private const MAX_PACK_BYTES = 4 * 1024 * 1024 * 1024;

    public function __construct(
        private readonly Modrinth $modrinth,
        private readonly CurseForge $curseforge,
        private readonly Ftb $ftb,
    ) {}

    /**
     * @return array{name: string, version: string, mc: string, loader: string, loader_version: ?string, java: int,
     *               steps: list<array<string, mixed>>, notes: list<string>, icon: ?string, memory?: ?int}
     */
    public function resolve(string $source, string $projectId, string $versionId): array
    {
        return match ($source) {
            'modrinth' => $this->modrinth($projectId, $versionId),
            'curseforge' => $this->curseforge($projectId, $versionId),
            'ftb' => $this->ftb((int) $projectId, (int) $versionId),
            default => throw new ContentException(__('Nieznane źródło modpacków.')),
        };
    }

    // --- Modrinth (.mrpack) -------------------------------------------------------------

    private function modrinth(string $projectId, string $versionId): array
    {
        $project = $this->modrinth->project($projectId);
        if (($project['server_side'] ?? null) === 'unsupported') {
            throw new ContentException(__('To modpack tylko dla klienta — nie da się go uruchomić jako serwer.'));
        }
        $version = $this->modrinth->versionById($versionId);
        if ($version['project_id'] !== $project['id']) {
            throw new ContentException(__('Ta wersja nie należy do wybranego modpacka.'));
        }
        $file = collect($version['files'])->first(fn ($f) => str_ends_with($f['filename'], '.mrpack'));
        if ($file === null) {
            throw new ContentException(__('Ta wersja nie ma pliku .mrpack.'));
        }

        $index = $this->readZipJson($file['url'], 'modrinth.index.json', $file['sha512'] ?? null);
        $deps = $index['dependencies'] ?? [];
        $mc = (string) ($deps['minecraft'] ?? '');
        [$loader, $loaderVersion] = match (true) {
            isset($deps['neoforge']) => ['neoforge', $deps['neoforge']],
            isset($deps['forge']) => ['forge', $deps['forge']],
            isset($deps['fabric-loader']) => ['fabric', $deps['fabric-loader']],
            isset($deps['quilt-loader']) => ['quilt', $deps['quilt-loader']],
            default => ['vanilla', null],
        };

        $steps = [];
        $skipped = 0;
        foreach ($index['files'] ?? [] as $f) {
            if (($f['env']['server'] ?? 'required') === 'unsupported') {
                $skipped++;

                continue;
            }
            $steps[] = [
                'op' => 'download',
                'url' => (string) ($f['downloads'][0] ?? ''),
                'path' => $this->safePath((string) ($f['path'] ?? '')),
                'sha1' => $f['hashes']['sha1'] ?? null,
                'sha512' => $f['hashes']['sha512'] ?? null,
                'size' => isset($f['fileSize']) ? (int) $f['fileSize'] : null,
                'label' => basename((string) ($f['path'] ?? '')),
            ];
        }
        $steps[] = ['op' => 'extract', 'url' => $file['url'], 'sha512' => $file['sha512'] ?? null,
            'prefixes' => ['overrides/' => '', 'server-overrides/' => ''], 'label' => __('Konfiguracja paczki (overrides)')];

        return $this->plan($project['name'], $version['number'] ?: $version['name'], $mc, $loader, $loaderVersion, $steps,
            $skipped ? [__('Pominięto :count plików tylko dla klienta.', ['count' => $skipped])] : [], $project['icon']);
    }

    // --- CurseForge ------------------------------------------------------------------------

    private function curseforge(string $projectId, string $fileId): array
    {
        if (! CurseForge::enabled()) {
            throw new ContentException(__('CurseForge wymaga klucza API — administrator ustawia go w VIRTHUB_CURSEFORGE_API_KEY.'));
        }
        $project = $this->curseforge->project($projectId);
        $file = $this->curseforge->fileById($projectId, $fileId);
        $packUrl = $file['files'][0]['url'] ?? null;
        if (! $packUrl) {
            throw new ContentException(__('Autor tego modpacka nie pozwala na pobieranie przez zewnętrzne aplikacje.'));
        }

        $manifest = $this->readZipJson($packUrl, 'manifest.json', null, $file['files'][0]['sha1'] ?? null);
        $mc = (string) ($manifest['minecraft']['version'] ?? '');
        $primary = collect($manifest['minecraft']['modLoaders'] ?? [])->firstWhere('primary', true)
            ?? ($manifest['minecraft']['modLoaders'][0] ?? null);
        [$loader, $loaderVersion] = $this->splitLoaderId((string) ($primary['id'] ?? ''));
        $overrides = trim((string) ($manifest['overrides'] ?? 'overrides'), '/').'/';

        // Bez server packa autor nie przygotował paczki pod serwer: lista modów
        // z manifestu to wersja kliencka (CurseForge nie oznacza modów klienckich).
        if (! $file['server_pack_file_id']) {
            throw new ContentException(__('Ta wersja nie ma server packa — to paczka tylko dla klienta. Wybierz wersję z server packiem.'));
        }
        $server = $this->curseforge->fileById($projectId, (string) $file['server_pack_file_id']);
        $url = $server['files'][0]['url'] ?? null;
        if (! $url) {
            throw new ContentException(__('Autor tego modpacka nie pozwala na pobieranie server packa przez zewnętrzne aplikacje.'));
        }
        $steps = [['op' => 'extract', 'url' => $url, 'sha1' => $server['files'][0]['sha1'] ?? null, 'strip_root' => true,
            'skip' => ['start.bat', 'startserver.bat', 'run.bat', 'start.sh', 'startserver.sh', 'run.sh'],
            'label' => __('Server pack: :name', ['name' => $server['files'][0]['filename']])]];
        $notes = [__('Użyto server packa przygotowanego przez autora.')];

        return $this->plan($project['name'], $file['name'], $mc, $loader, $loaderVersion, $steps, $notes, $project['icon']);
    }

    // --- Feed The Beast -------------------------------------------------------------------------

    private function ftb(int $packId, int $versionId): array
    {
        $pack = $this->ftb->pack($packId);
        $version = $this->ftb->version($packId, $versionId);
        $targets = collect($version['targets'] ?? []);
        $mc = (string) ($targets->firstWhere('name', 'minecraft')['version'] ?? '');
        $loaderTarget = $targets->firstWhere('type', 'modloader');
        $loader = strtolower((string) ($loaderTarget['name'] ?? 'vanilla'));
        $loaderVersion = $loaderTarget['version'] ?? null;

        $steps = [];
        $skipped = 0;
        $needsCf = [];
        foreach ($version['files'] ?? [] as $f) {
            if ($f['clientonly'] ?? false) {
                $skipped++;

                continue;
            }
            $dir = trim(preg_replace('#^\./#', '', (string) ($f['path'] ?? '')), '/');
            $path = $this->safePath(($dir !== '' && $dir !== '.' ? $dir.'/' : '').$f['name']);
            if (empty($f['url'])) {
                $needsCf[] = ['path' => $path, 'cf' => $f['curseforge'] ?? null, 'sha1' => $f['sha1'] ?? null, 'size' => $f['size'] ?? null];

                continue;
            }
            $steps[] = ['op' => 'download', 'url' => (string) $f['url'], 'path' => $path, 'sha1' => $f['sha1'] ?? null,
                'size' => isset($f['size']) ? (int) $f['size'] : null, 'label' => (string) $f['name']];
        }

        if ($needsCf !== []) {
            if (! CurseForge::enabled()) {
                throw new ContentException(__('Część plików tej paczki leży na CurseForge — administrator musi ustawić VIRTHUB_CURSEFORGE_API_KEY.'));
            }
            $byId = $this->curseforge->filesByIds(array_values(array_filter(array_map(fn ($f) => (int) ($f['cf']['file'] ?? 0), $needsCf))));
            foreach ($needsCf as $f) {
                $url = $byId[(int) ($f['cf']['file'] ?? 0)]['files'][0]['url'] ?? null;
                if (! $url) {
                    throw new ContentException(__('Plik :file nie jest dostępny do pobrania.', ['file' => basename($f['path'])]));
                }
                $steps[] = ['op' => 'download', 'url' => $url, 'path' => $f['path'], 'sha1' => $f['sha1'], 'size' => $f['size'], 'label' => basename($f['path'])];
            }
        }

        $plan = $this->plan((string) $pack['name'], (string) ($version['name'] ?? ''), $mc, $loader, $loaderVersion, $steps,
            $skipped ? [__('Pominięto :count plików tylko dla klienta.', ['count' => $skipped])] : [], $this->ftb->summary($pack)['icon']);
        $plan['memory'] = $version['specs']['recommended'] ?? null;

        return $plan;
    }

    // --- pomocnicze -------------------------------------------------------------------------------

    private function plan(string $name, string $version, string $mc, string $loader, ?string $loaderVersion, array $steps, array $notes, ?string $icon): array
    {
        if ($mc === '') {
            throw new ContentException(__('Paczka nie podaje wersji Minecrafta.'));
        }
        if (! in_array($loader, Loaders::TYPES, true)) {
            throw new ContentException(__('Loader :loader nie jest obsługiwany.', ['loader' => $loader]));
        }

        return [
            'name' => $name, 'version' => $version, 'mc' => $mc, 'loader' => $loader, 'loader_version' => $loaderVersion,
            'java' => Loaders::javaFor($mc), 'steps' => $steps, 'notes' => $notes, 'icon' => $icon,
        ];
    }

    /** @return array{0: string, 1: ?string} */
    private function splitLoaderId(string $id): array
    {
        if (preg_match('/^(neoforge|forge|fabric|quilt)-(.+)$/', $id, $m)) {
            return [$m[1], $m[2]];
        }

        throw new ContentException(__('Nieznany loader w manifeście paczki: :id', ['id' => $id ?: '—']));
    }

    /** Ścieżka z paczki: bez „..”, bez ścieżek absolutnych. */
    private function safePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            throw new ContentException(__('Paczka zawiera niebezpieczną ścieżkę: :path', ['path' => Str::limit($path, 80)]));
        }

        return $path;
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new ContentException(__('Nieprawidłowa nazwa pliku w paczce.'));
        }

        return $name;
    }

    /**
     * Jeden plik JSON z archiwum paczki. Najpierw sam wpis przez HTTP Range
     * (kilka małych zapytań, niezależnie od rozmiaru paczki); całe archiwum
     * pobieramy tylko, gdy serwer Range nie obsługuje. Sumę całego archiwum
     * i tak sprawdza agent przy rozpakowaniu.
     */
    private function readZipJson(string $url, string $entry, ?string $sha512 = null, ?string $sha1 = null): array
    {
        if (! str_starts_with($url, 'https://')) {
            throw new ContentException(__('Adres paczki musi być https.'));
        }
        $json = RemoteZip::read($url, $entry);
        if ($json !== null) {
            $data = json_decode($json, true);
            if (! is_array($data)) {
                throw new ContentException(__('W paczce brakuje :file.', ['file' => $entry]));
            }

            return $data;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'vhpack');
        try {
            $response = ContentHttp::client(timeout: 1800)->withOptions(['sink' => $tmp])->get($url);
            if (! $response->successful()) {
                throw new ContentException(__('Nie udało się pobrać paczki (HTTP :code).', ['code' => $response->status()]));
            }
            if (filesize($tmp) > self::MAX_PACK_BYTES) {
                throw new ContentException(__('Archiwum paczki ma ponad 4 GB — panel go nie przetworzy.'));
            }
            if (($sha512 && hash_file('sha512', $tmp) !== $sha512) || ($sha1 && hash_file('sha1', $tmp) !== $sha1)) {
                throw new ContentException(__('Archiwum paczki ma złą sumę kontrolną.'));
            }
            $zip = new \ZipArchive;
            if ($zip->open($tmp) !== true) {
                throw new ContentException(__('Archiwum paczki jest uszkodzone.'));
            }
            $json = $zip->getFromName($entry);
            $zip->close();
            $data = is_string($json) ? json_decode($json, true) : null;
            if (! is_array($data)) {
                throw new ContentException(__('W paczce brakuje :file.', ['file' => $entry]));
            }

            return $data;
        } finally {
            @unlink($tmp);
        }
    }
}
