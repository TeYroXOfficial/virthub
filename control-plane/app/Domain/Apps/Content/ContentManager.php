<?php

namespace App\Domain\Apps\Content;

use App\Domain\Agent\AgentClient;
use App\Jobs\InstallContentJob;
use App\Models\AppAddon;
use App\Models\AppEgg;
use App\Models\AppJob;
use App\Models\AppServer;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Instalator modpacków, loaderów, pluginów i modów „jednym kliknięciem”.
 *
 * Katalog (wyszukiwanie, wersje) idzie prosto do serwisów, z filtrem
 * zgodności z serwerem. Instalacja to zadanie w kolejce panelu
 * (InstallContentJob), które rozwiązuje paczkę do listy kroków i zleca je
 * agentowi; wynik wraca callbackiem jak przy instalacji aplikacji.
 */
class ContentManager
{
    public const SOURCES = [
        'modpack' => ['modrinth' => Modrinth::NAME, 'curseforge' => CurseForge::NAME, 'ftb' => Ftb::NAME],
        'plugin' => ['modrinth' => Modrinth::NAME, 'hangar' => Hangar::NAME, 'curseforge' => CurseForge::NAME],
        'mod' => ['modrinth' => Modrinth::NAME, 'curseforge' => CurseForge::NAME],
    ];

    public const ACTIONS = ['modpack', 'loader', 'addon'];

    private const MAX_DEPENDENCIES = 15;

    public function __construct(
        private readonly Modrinth $modrinth,
        private readonly Hangar $hangar,
        private readonly CurseForge $curseforge,
        private readonly Ftb $ftb,
        private readonly Loaders $loaders,
        private readonly ModpackResolver $modpacks,
    ) {}

    /** @return array<string, string> dostępne źródła dla rodzaju treści */
    public function sources(string $kind): array
    {
        return array_filter(self::SOURCES[$kind] ?? [], fn ($name, $key) => $key !== 'curseforge' || CurseForge::enabled(), ARRAY_FILTER_USE_BOTH);
    }

    // --- katalog -------------------------------------------------------------------------------

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function search(string $kind, string $source, string $query, int $page, ServerProfile $profile): array
    {
        $this->assertSource($kind, $source);
        $mc = $kind === 'modpack' ? null : $profile->gameVersion();
        $loaders = $kind === 'modpack' ? [] : $profile->loaders();

        return match ($source) {
            'modrinth' => $this->modrinth->search($kind, $query, $mc, $loaders, $page),
            'hangar' => $this->hangar->search($query, $mc, $page),
            'curseforge' => $this->curseforge->search($kind, $query, $mc, $kind === 'mod' ? ($loaders[0] ?? null) : null, $page),
            'ftb' => $this->ftb->search($query, $page),
        };
    }

    /** @return array<string, mixed> */
    public function project(string $kind, string $source, string $id): array
    {
        $this->assertSource($kind, $source);

        $project = match ($source) {
            'modrinth' => $this->modrinth->project($id),
            'hangar' => $this->hangar->projectBySlug($id),
            'curseforge' => $this->curseforge->project($id),
            'ftb' => $this->ftb->summary($this->ftb->pack((int) $id)),
        };
        if ($kind === 'modpack' && ($project['server_side'] ?? null) === 'unsupported') {
            throw new ContentException(__('To modpack tylko dla klienta — nie da się go uruchomić jako serwer.'));
        }

        return $project;
    }

    /**
     * Wersje projektu. Dla pluginów i modów tylko zgodne z serwerem (wersja
     * gry i loader) — tego nie da się obejść z przeglądarki, bo instalacja
     * sprawdza zgodność ponownie.
     *
     * @return list<array<string, mixed>>
     */
    public function versions(string $kind, string $source, string $id, ServerProfile $profile): array
    {
        $this->assertSource($kind, $source);
        if ($kind === 'modpack') {
            return match ($source) {
                'modrinth' => $this->modrinth->versions($id),
                'curseforge' => array_values(array_filter($this->curseforge->versions($id, null, null), fn ($v) => $v['server_pack_file_id'])),
                'ftb' => $this->ftb->versions((int) $id),
            };
        }

        $mc = $profile->gameVersion();
        if ($mc === null) {
            throw new ContentException(__('Nie znam wersji Minecrafta na tym serwerze — uruchom go raz, żeby panel ją odczytał.'));
        }
        $loaders = $profile->loaders();
        $versions = match ($source) {
            'modrinth' => $this->modrinth->versions($id, $loaders, [$mc]),
            'hangar' => $this->hangar->versions($id, $mc),
            'curseforge' => $this->curseforge->versions($id, $mc, $kind === 'mod' ? ($loaders[0] ?? null) : null),
        };

        return array_values(array_filter($versions, fn ($v) => $this->compatible($v, $mc, $loaders, $source)));
    }

    /** @param  list<string>  $loaders */
    public function compatible(array $version, string $mc, array $loaders, string $source): bool
    {
        if (! in_array($mc, $version['game_versions'] ?? [], true)) {
            return false;
        }
        // CurseForge nie zawsze podaje platformę pluginów — wystarczy wersja gry.
        if ($source === 'curseforge' && ($version['loaders'] ?? []) === []) {
            return true;
        }

        return array_intersect($loaders, $version['loaders'] ?? []) !== [];
    }

    // --- modpack i loader ------------------------------------------------------------------------

    public function queueModpack(AppServer $app, string $source, string $projectId, string $versionId, bool $wipeWorld, User $actor, bool $wipePlugins = false): AppJob
    {
        $this->assertSource('modpack', $source);

        return $this->dispatch($app, 'modpack', [
            'source' => $source, 'project_id' => $projectId, 'version_id' => $versionId, 'wipe_world' => $wipeWorld, 'wipe_plugins' => $wipePlugins,
        ], $actor);
    }

    public function queueLoader(AppServer $app, string $loader, string $mc, ?string $loaderVersion, bool $wipeWorld, User $actor, bool $wipePlugins = false): AppJob
    {
        if (! in_array($loader, Loaders::TYPES, true)) {
            throw new ContentException(__('Nieznany loader: :loader', ['loader' => $loader]));
        }

        return $this->dispatch($app, 'loader', [
            'loader' => $loader, 'mc' => $mc, 'loader_version' => $loaderVersion, 'wipe_world' => $wipeWorld, 'wipe_plugins' => $wipePlugins,
        ], $actor);
    }

    /**
     * Rozwiązanie modpacka/loadera do kroków — w zadaniu kolejki (pobiera
     * archiwum paczki). Przełącza aplikację na egg z modami i właściwą Javę.
     *
     * @return array{request: array<string, mixed>, minecraft: array<string, mixed>}
     */
    public function buildServer(AppServer $app, AppJob $job): array
    {
        $p = $job->payload;
        if ($job->action === 'modpack') {
            $plan = $this->modpacks->resolve($p['source'], (string) $p['project_id'], (string) $p['version_id']);
            $label = __('Instalacja modpacka :name :version', ['name' => $plan['name'], 'version' => $plan['version']]);
        } else {
            $plan = ['name' => null, 'version' => null, 'mc' => $p['mc'], 'loader' => $p['loader'], 'loader_version' => $p['loader_version'] ?? null,
                'java' => Loaders::javaFor($p['mc']), 'steps' => [], 'notes' => [], 'icon' => null];
            $label = __('Instalacja serwera :loader :version', ['loader' => ucfirst($p['loader']), 'version' => $p['mc']]);
        }

        // Minecraft nie obniża wersji świata — starszy serwer by go nie wczytał.
        $current = $app->minecraft['mc'] ?? null;
        if ($current && ! ($p['wipe_world'] ?? false) && version_compare($plan['mc'], $current, '<')) {
            throw new ContentException(__('Świat jest z Minecrafta :current, a :target to starsza wersja — nie wczyta go. Zaznacz „Usuń też świat” albo wybierz wersję :current lub nowszą.', [
                'current' => $current, 'target' => $plan['mc'],
            ]));
        }

        $loader = $this->loaders->steps($plan['loader'], $plan['mc'], $plan['loader_version']);
        $cleanup = Loaders::SERVER_FILES;
        if ($p['wipe_world'] ?? false) {
            // Agent czyta level-name z server.properties (świat nie musi nazywać się „world”).
            $cleanup[] = '@world';
        }
        if ($p['wipe_plugins'] ?? false) {
            $cleanup[] = 'plugins';
        }

        $steps = [
            ['op' => 'delete', 'paths' => $cleanup, 'label' => ($p['wipe_world'] ?? false)
                ? __('Usuwanie plików poprzedniego serwera razem ze światem')
                : __('Usuwanie plików poprzedniego serwera (świat zostaje)')],
            ...$loader['steps'],
            ...$plan['steps'],
            ['op' => 'write', 'path' => 'start.sh', 'content' => Loaders::startScript(), 'executable' => true],
            ['op' => 'write', 'path' => '.virthub-version', 'content' => $plan['mc']."\n"],
        ];
        if ($plan['notes'] !== []) {
            // Uwagi (pominięte pliki klienckie itp.) widać w konsoli i w pliku.
            $steps[] = ['op' => 'write', 'path' => '.virthub-notes.txt', 'content' => implode("\n", $plan['notes'])."\n",
                'label' => implode(' ', $plan['notes'])];
        }

        $this->switchToModdedEgg($app, $plan['java']);

        return [
            'request' => [
                'label' => $label,
                'exclusive' => true,
                'steps' => array_map(fn ($s) => array_filter($s, fn ($v) => $v !== null), $steps),
                'spec' => \App\Domain\Apps\AppPayload::spec($app->refresh()),
            ],
            'minecraft' => [
                'platform' => $plan['loader'],
                'mc' => $plan['mc'],
                'loader_version' => $loader['loader_version'] ?? $plan['loader_version'],
                'modpack' => $job->action === 'modpack' ? [
                    'source' => $p['source'], 'id' => (string) $p['project_id'], 'version_id' => (string) $p['version_id'],
                    'name' => $plan['name'], 'version' => $plan['version'], 'icon' => $plan['icon'],
                ] : null,
                'memory_recommended' => $plan['memory'] ?? null,
            ],
        ];
    }

    private function switchToModdedEgg(AppServer $app, int $java): void
    {
        $egg = $app->egg?->hasFeature('modpacks') ? $app->egg : AppEgg::query()->where('builtin_key', 'minecraft-modded')->first();
        if ($egg === null) {
            // Panel zaktualizowany, a szablony jeszcze nie — wgrywamy wbudowane.
            app(\App\Domain\Apps\EggImporter::class)->importBuiltin();
            $egg = AppEgg::query()->where('builtin_key', 'minecraft-modded')->first();
        }
        if ($egg === null) {
            throw new ContentException(__('Brakuje wbudowanego szablonu „Minecraft: modpack / mody” — administrator wgrywa go w Administracja → Aplikacje → Szablony.'));
        }
        $image = Loaders::imageFor($egg->images(), $java) ?? $egg->defaultImage();
        $env = $egg->id === $app->app_egg_id ? ($app->environment ?? []) : [];
        $env['EULA'] = 'true'; // klient zaakceptował EULA przy instalacji

        $app->forceFill(['app_egg_id' => $egg->id, 'docker_image' => $image, 'environment' => $env])->save();
    }

    // --- pluginy i mody ---------------------------------------------------------------------------

    /**
     * Plugin/mod z wymaganymi zależnościami — rozwiązanie od razu (kilka
     * zapytań do API), pobieranie w kolejce agenta.
     */
    public function queueAddon(AppServer $app, string $source, string $projectId, string $versionId, User $actor): AppJob
    {
        $profile = new ServerProfile($app);
        $kind = $profile->addonKind();
        if ($kind === null) {
            throw new ContentException(__('Ten serwer nie przyjmuje pluginów ani modów — zainstaluj Paper albo loader z modami.'));
        }
        $this->assertSource($kind, $source);
        $mc = $profile->gameVersion();
        if ($mc === null) {
            throw new ContentException(__('Nie znam wersji Minecrafta na tym serwerze — uruchom go raz, żeby panel ją odczytał.'));
        }

        $version = collect($this->versions($kind, $source, $projectId, $profile))->firstWhere('id', $versionId);
        if ($version === null) {
            throw new ContentException(__('Ta wersja nie pasuje do serwera (Minecraft :mc, :platform) — wybierz inną.', ['mc' => $mc, 'platform' => $profile->platform()]));
        }

        $collected = [];
        $this->collect($app, $kind, $source, $this->project($kind, $source, $projectId), $version, $profile, $collected, false);
        $addons = array_values($collected);

        $steps = [];
        $installed = $app->addons()->get()->keyBy(fn (AppAddon $a) => $a->source.':'.$a->project_id);
        foreach ($addons as $addon) {
            $dir = $kind === 'mod' ? 'mods' : 'plugins';
            $old = $installed[$addon['source'].':'.$addon['project_id']] ?? null;
            if ($old && $old->filename !== $addon['filename']) {
                $steps[] = ['op' => 'delete', 'paths' => [$dir.'/'.$old->filename]];
            }
            $steps[] = array_filter([
                'op' => 'download', 'url' => $addon['file']['url'], 'path' => $dir.'/'.$addon['filename'],
                'sha1' => $addon['file']['sha1'] ?? null, 'sha512' => $addon['file']['sha512'] ?? null,
                'sha256' => $addon['file']['sha256'] ?? null, 'size' => $addon['file']['size'] ?: null,
                'label' => $addon['name'].' '.$addon['version'],
            ], fn ($v) => $v !== null);
        }

        return $this->dispatch($app, 'addon', [
            'kind' => $kind,
            'name' => $addons[0]['name'],
            'addons' => array_map(fn ($a) => array_diff_key($a, ['file' => 1]), $addons),
            'request' => ['label' => __('Instalacja :name', ['name' => $addons[0]['name']]), 'exclusive' => false, 'steps' => $steps],
        ], $actor);
    }

    /** Dodatek i (dla Modrinth/CurseForge) jego wymagane zależności, bez duplikatów. */
    private function collect(AppServer $app, string $kind, string $source, array $project, array $version, ServerProfile $profile, array &$out, bool $dependency): void
    {
        $key = $source.':'.$project['id'];
        if (isset($out[$key]) || count($out) > self::MAX_DEPENDENCIES) {
            return;
        }
        $file = $version['files'][0] ?? null;
        if (! $file || empty($file['url'])) {
            throw new ContentException(__('Autor :name nie pozwala na pobieranie przez zewnętrzne aplikacje.', ['name' => $project['name']]));
        }
        $filename = basename(str_replace('\\', '/', (string) $file['filename']));
        if ($filename === '' || ! preg_match('/\.(jar|zip)$/i', $filename)) {
            throw new ContentException(__('Plik :file nie wygląda na plugin/mod (.jar).', ['file' => $filename ?: '—']));
        }

        $out[$key] = [
            'source' => $source, 'project_id' => (string) $project['id'], 'version_id' => (string) $version['id'],
            'name' => (string) $project['name'], 'version' => (string) ($version['number'] ?: $version['name']),
            'filename' => $filename, 'icon' => $project['icon'] ?? null, 'dependency' => $dependency, 'file' => $file,
        ];

        foreach ($version['dependencies'] ?? [] as $dep) {
            if (! ($dep['required'] ?? false) || empty($dep['project_id'])) {
                continue;
            }
            if ($app->addons()->where('source', $source)->where('project_id', $dep['project_id'])->exists()) {
                continue; // już jest na serwerze
            }
            $candidates = $dep['version_id'] && $source === 'modrinth'
                ? [$this->modrinth->versionById($dep['version_id'])]
                : $this->versions($kind, $source, (string) $dep['project_id'], $profile);
            $best = collect($candidates)->first(fn ($v) => ($v['type'] ?? 'release') === 'release') ?? ($candidates[0] ?? null);
            if ($best === null) {
                $name = $this->project($kind, $source, (string) $dep['project_id'])['name'] ?? $dep['project_id'];
                throw new ContentException(__('Wymagana zależność :name nie ma wersji zgodnej z tym serwerem.', ['name' => $name]));
            }
            $this->collect($app, $kind, $source, $this->project($kind, $source, (string) $dep['project_id']), $best, $profile, $out, true);
        }
    }

    public function removeAddon(AppServer $app, AppAddon $addon, User $actor): void
    {
        (new AgentClient($app->hypervisor))->appFiles($app->uuid, 'delete', ['path' => $addon->directory().'/'.$addon->filename]);
        AuditLog::record('app.addon_removed', $app, ['name' => $addon->name, 'file' => $addon->filename], $actor);
        $addon->delete();
    }

    /**
     * Najnowsza zgodna wersja dla każdego dodatku (aktualizacje).
     *
     * @return array<int, array<string, mixed>> id dodatku => wersja
     */
    public function updates(AppServer $app): array
    {
        $profile = new ServerProfile($app);
        $kind = $profile->addonKind();
        if ($kind === null || $profile->gameVersion(false) === null) {
            return [];
        }
        $out = [];
        foreach ($app->addons()->limit(40)->get() as $addon) {
            try {
                $latest = collect($this->versions($kind, $addon->source, $addon->project_id, $profile))
                    ->first(fn ($v) => ($v['type'] ?? 'release') === 'release');
            } catch (ContentException) {
                continue;
            }
            if ($latest && (string) $latest['id'] !== $addon->version_id) {
                $out[$addon->id] = $latest;
            }
        }

        return $out;
    }

    // --- zadania i wyniki ---------------------------------------------------------------------------

    private function dispatch(AppServer $app, string $action, array $payload, User $actor): AppJob
    {
        if (! $app->acceptsCommands()) {
            throw new ContentException(__('Aplikacja musi być zainstalowana i niezawieszona.'));
        }
        if ($app->jobs()->whereIn('status', [AppJob::STATUS_QUEUED, AppJob::STATUS_RUNNING])->exists()) {
            throw new ContentException(__('Na tej aplikacji trwa już inna instalacja — poczekaj, aż się skończy.'));
        }

        $job = AppJob::query()->create([
            'app_server_id' => $app->id, 'user_id' => $actor->id, 'action' => $action,
            'payload' => $payload, 'status' => AppJob::STATUS_QUEUED,
        ]);
        AuditLog::record('app.content', $app, ['action' => $action] + array_intersect_key($payload, array_flip(['source', 'project_id', 'version_id', 'loader', 'mc', 'name'])), $actor);
        if ($action !== 'addon') {
            // Od razu „instalacja” — zasilanie zablokowane, konsola czeka na log.
            // Błąd przygotowania (applyResult) przywraca stan „gotowa”.
            $app->forceFill(['status' => AppServer::STATUS_INSTALLING, 'status_message' => __('Przygotowuję instalację…')])->save();
        }
        InstallContentJob::dispatch($job->id);

        return $job;
    }

    /** Wynik zadania treści z węzła (AppJobApplier). */
    public function applyResult(AppJob $job, bool $ok, ?string $error): void
    {
        $app = $job->server;
        $job->forceFill(['status' => $ok ? AppJob::STATUS_DONE : AppJob::STATUS_FAILED, 'error' => $ok ? null : $error, 'finished_at' => now()])->save();
        if ($app === null) {
            return;
        }

        if (in_array($job->action, ['modpack', 'loader'], true)) {
            $app->forceFill(['status' => AppServer::STATUS_READY, 'status_message' => null])->save();
            if ($ok) {
                $app->forceFill(['minecraft' => $job->payload['minecraft'] ?? $app->minecraft])->save();
                // Nowy zestaw modów — stare wpisy nie odpowiadają już plikom.
                $app->addons()->delete();
            }

            return;
        }

        if ($ok && $job->action === 'addon') {
            foreach ($job->payload['addons'] ?? [] as $a) {
                AppAddon::query()->updateOrCreate(
                    ['app_server_id' => $app->id, 'source' => $a['source'], 'project_id' => $a['project_id']],
                    [
                        'kind' => $job->payload['kind'], 'version_id' => $a['version_id'], 'name' => mb_substr($a['name'], 0, 200),
                        'version' => mb_substr((string) $a['version'], 0, 100), 'filename' => $a['filename'], 'icon' => $a['icon'],
                        'game_version' => $app->minecraft['mc'] ?? null, 'dependency' => (bool) $a['dependency'],
                    ],
                );
            }
        }
    }

    private function assertSource(string $kind, string $source): void
    {
        if (! array_key_exists($source, $this->sources($kind))) {
            throw new ContentException(__('To źródło nie jest dostępne.'));
        }
    }
}
