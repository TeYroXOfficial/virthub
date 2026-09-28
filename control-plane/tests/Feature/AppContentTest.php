<?php

namespace Tests\Feature;

use App\Domain\Apps\AppJobApplier;
use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\Content\ContentManager;
use App\Domain\Apps\Content\Loaders;
use App\Domain\Apps\EggImporter;
use App\Jobs\InstallAppJob;
use App\Jobs\InstallContentJob;
use App\Models\AppAddon;
use App\Models\AppEgg;
use App\Models\AppJob;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Instalator modpacków, loaderów, pluginów i modów. */
class AppContentTest extends TestCase
{
    use RefreshDatabase;

    private Hypervisor $node;

    private User $customer;

    private AppServer $paper;

    /** @var list<array<string, mixed>> żądania do agenta /content */
    private array $agentRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->node = Hypervisor::factory()->create([
            'agent_url' => 'https://node.test:8443',
            'apps_enabled' => true, 'app_port_start' => 25565, 'app_port_end' => 25574,
            'ram_mb_total' => 16384, 'disk_gb_total' => 500,
            'last_health' => ['apps' => ['available' => true], 'public_ipv4' => '203.0.113.10'],
        ]);
        $this->customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        app(EggImporter::class)->importBuiltin();
        $plan = AppPlan::query()->create(['name' => 'S', 'memory_mb' => 4096, 'cpu_percent' => 200, 'disk_mb' => 20480, 'ports' => 1]);
        $this->paper = app(AppProvisioner::class)->order(
            $this->customer, AppEgg::query()->where('builtin_key', 'minecraft-paper')->firstOrFail(), $plan, 'Survival',
        );
        $this->paper->update(['status' => AppServer::STATUS_READY]);
        AppJob::query()->update(['status' => AppJob::STATUS_DONE, 'finished_at' => now()]);
    }

    private function fakeApis(array $extra = []): void
    {
        $mrpack = $this->mrpack();
        Http::fake($extra + [
            'node.test:8443/apps/*/content' => function (Request $r) {
                $this->agentRequests[] = $r->data();

                return Http::response(['job_id' => 'agent-content-'.count($this->agentRequests)], 202);
            },
            'node.test:8443/apps/*/files/read' => Http::response(['content_base64' => base64_encode("1.20.1\n")]),
            'node.test:8443/apps/*/files/delete' => Http::response(['deleted' => true]),
            'node.test:8443/*' => Http::response(['uuid' => 'x', 'restart_required' => false]),
            'api.modrinth.com/v2/search*' => Http::response(['total_hits' => 1, 'hits' => [[
                'project_id' => 'LUCK', 'slug' => 'luckperms', 'title' => 'LuckPerms', 'description' => 'Uprawnienia', 'downloads' => 5000000,
                'icon_url' => null, 'author' => 'Luck',
            ]]]),
            'api.modrinth.com/v2/project/LUCK/version*' => Http::response([
                $this->mrVersion('v-new', 'LUCK', '5.4.1', ['1.20.1', '1.20.4'], ['paper', 'bukkit'], deps: [['project_id' => 'VAULT', 'dependency_type' => 'required']]),
                $this->mrVersion('v-old', 'LUCK', '5.3.0', ['1.19.4'], ['bukkit']),
            ]),
            'api.modrinth.com/v2/project/VAULT/version*' => Http::response([$this->mrVersion('vault-1', 'VAULT', '1.7.3', ['1.20.1'], ['bukkit'])]),
            'api.modrinth.com/v2/project/LUCK' => Http::response(['id' => 'LUCK', 'slug' => 'luckperms', 'title' => 'LuckPerms', 'description' => 'x', 'downloads' => 1]),
            'api.modrinth.com/v2/project/VAULT' => Http::response(['id' => 'VAULT', 'slug' => 'vault', 'title' => 'Vault', 'description' => 'x', 'downloads' => 1]),
            'api.modrinth.com/v2/project/PACK' => Http::response(['id' => 'PACK', 'slug' => 'pack', 'title' => 'Super Pack', 'description' => 'x', 'downloads' => 1, 'project_type' => 'modpack']),
            'api.modrinth.com/v2/version/pack-v1' => Http::response($this->mrVersion('pack-v1', 'PACK', '2.0', ['1.20.1'], ['fabric'], file: [
                'url' => 'https://cdn.modrinth.com/data/PACK/pack.mrpack', 'filename' => 'pack.mrpack', 'primary' => true,
                'size' => strlen($mrpack), 'hashes' => ['sha1' => sha1($mrpack), 'sha512' => hash('sha512', $mrpack)],
            ])),
            'cdn.modrinth.com/data/PACK/pack.mrpack' => Http::response($mrpack),
            'piston-meta.mojang.com/mc/game/version_manifest_v2.json' => Http::response(['versions' => [
                ['id' => '1.20.1', 'type' => 'release', 'url' => 'https://piston-meta.mojang.com/v1/1.20.1.json'],
            ]]),
            'piston-meta.mojang.com/v1/1.20.1.json' => Http::response(['downloads' => ['server' => [
                'url' => 'https://piston-data.mojang.com/server.jar', 'sha1' => str_repeat('a', 40), 'size' => 50000000,
            ]]]),
            'meta.fabricmc.net/v2/versions/installer' => Http::response([['version' => '1.0.1', 'stable' => true]]),
            'meta.fabricmc.net/v2/versions/loader/1.20.1' => Http::response([['loader' => ['version' => '0.15.11', 'stable' => true]]]),
            'files.minecraftforge.net/*' => Http::response(['promos' => ['1.20.1-recommended' => '47.2.0']]),
            'maven.minecraftforge.net/*' => Http::response(str_repeat('b', 40)),
        ]);
    }

    private function mrVersion(string $id, string $project, string $number, array $games, array $loaders, array $deps = [], ?array $file = null): array
    {
        return [
            'id' => $id, 'project_id' => $project, 'name' => $number, 'version_number' => $number, 'game_versions' => $games,
            'loaders' => $loaders, 'version_type' => 'release', 'date_published' => '2026-09-01T00:00:00Z', 'dependencies' => $deps,
            'files' => [$file ?? ['url' => "https://cdn.modrinth.com/data/{$project}/{$id}.jar", 'filename' => "{$project}-{$number}.jar",
                'primary' => true, 'size' => 1000, 'hashes' => ['sha1' => sha1($id), 'sha512' => hash('sha512', $id)]]],
        ];
    }

    private function mrpack(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mrp');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('modrinth.index.json', json_encode([
            'formatVersion' => 1, 'game' => 'minecraft', 'name' => 'Super Pack',
            'dependencies' => ['minecraft' => '1.20.1', 'fabric-loader' => '0.15.11'],
            'files' => [
                ['path' => 'mods/sodium.jar', 'hashes' => ['sha1' => str_repeat('1', 40), 'sha512' => str_repeat('1', 128)],
                    'env' => ['client' => 'required', 'server' => 'unsupported'], 'downloads' => ['https://cdn.modrinth.com/sodium.jar'], 'fileSize' => 10],
                ['path' => 'mods/lithium.jar', 'hashes' => ['sha1' => str_repeat('2', 40), 'sha512' => str_repeat('2', 128)],
                    'env' => ['client' => 'required', 'server' => 'required'], 'downloads' => ['https://cdn.modrinth.com/lithium.jar'], 'fileSize' => 20],
            ],
        ]));
        $zip->addFromString('overrides/config/a.toml', 'a=1');
        $zip->close();
        $data = file_get_contents($tmp);
        unlink($tmp);

        return $data;
    }

    private function runQueued(): AppJob
    {
        $job = AppJob::query()->latest('id')->firstOrFail();
        (new InstallContentJob($job->id))->handle(app(ContentManager::class));

        return $job->fresh();
    }

    private function agentResult(AppJob $job, string $status = 'done', ?string $error = null): void
    {
        app(AppJobApplier::class)->apply($job->fresh(), ['status' => $status, 'error' => $error]);
    }

    // --- pluginy ------------------------------------------------------------------------

    public function test_zakladki_i_katalog_pluginow_dla_papera(): void
    {
        $this->fakeApis();

        $this->actingAs($this->customer)->get(route('panel.apps.addons', $this->paper))
            ->assertOk()
            ->assertSee('Pluginy')
            ->assertSee('Modpacki')
            ->assertSee('LuckPerms')
            ->assertSee('Minecraft 1.20.1');

        // Wersja gry odczytana z pliku z instalacji i zapamiętana; wyszukiwanie z filtrem zgodności.
        $this->assertSame('1.20.1', $this->paper->fresh()->minecraft['mc']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.modrinth.com/v2/search')
            && str_contains(urldecode($r->url()), 'versions:1.20.1') && str_contains(urldecode($r->url()), 'categories:paper'));
    }

    public function test_lista_wersji_pokazuje_tylko_zgodne(): void
    {
        $this->fakeApis();

        $this->actingAs($this->customer)->get(route('panel.apps.addons.show', [$this->paper, 'modrinth', 'LUCK']))
            ->assertOk()
            ->assertSee('5.4.1')
            ->assertDontSee('5.3.0');
    }

    public function test_instalacja_pluginu_z_zaleznoscia(): void
    {
        $this->fakeApis();

        $this->actingAs($this->customer)->post(route('panel.apps.addons.install', $this->paper), [
            'source' => 'modrinth', 'project' => 'LUCK', 'version' => 'v-new',
        ])->assertRedirect(route('panel.apps.addons', $this->paper));
        Queue::assertPushed(InstallContentJob::class);

        $job = $this->runQueued();
        $this->assertSame('agent-content-1', $job->agent_job_id);
        $request = $this->agentRequests[0];
        $this->assertFalse($request['exclusive']);
        $paths = array_column($request['steps'], 'path');
        $this->assertSame(['plugins/LUCK-5.4.1.jar', 'plugins/VAULT-1.7.3.jar'], $paths);
        $this->assertSame(sha1('v-new'), $request['steps'][0]['sha1']);
        // Plugin instaluje się w tle — aplikacja nie przechodzi w stan instalacji.
        $this->assertTrue($this->paper->fresh()->isReady());

        $this->agentResult($job);
        $addons = $this->paper->addons()->get()->keyBy('project_id');
        $this->assertSame('5.4.1', $addons['LUCK']->version);
        $this->assertTrue($addons['VAULT']->dependency);
        $this->assertSame('plugin', $addons['LUCK']->kind);
    }

    public function test_niezgodna_wersja_jest_odrzucana(): void
    {
        $this->fakeApis();

        $this->actingAs($this->customer)->post(route('panel.apps.addons.install', $this->paper), [
            'source' => 'modrinth', 'project' => 'LUCK', 'version' => 'v-old',
        ])->assertSessionHasErrors('content');
        Queue::assertNotPushed(InstallContentJob::class);
    }

    public function test_aktualizacja_podmienia_plik_a_usuniecie_kasuje_go(): void
    {
        $this->fakeApis();
        $this->paper->forceFill(['minecraft' => ['mc' => '1.20.1']])->save();
        $old = AppAddon::query()->create([
            'app_server_id' => $this->paper->id, 'kind' => 'plugin', 'source' => 'modrinth', 'project_id' => 'LUCK',
            'version_id' => 'v-old', 'name' => 'LuckPerms', 'version' => '5.3.0', 'filename' => 'LuckPerms-5.3.0.jar',
        ]);
        AppAddon::query()->create([
            'app_server_id' => $this->paper->id, 'kind' => 'plugin', 'source' => 'modrinth', 'project_id' => 'VAULT',
            'version_id' => 'vault-1', 'name' => 'Vault', 'version' => '1.7.3', 'filename' => 'VAULT-1.7.3.jar',
        ]);

        $this->actingAs($this->customer)->get(route('panel.apps.addons', [$this->paper, 'updates' => 1]))
            ->assertOk()->assertSee('Aktualizuj do 5.4.1');

        $this->actingAs($this->customer)->post(route('panel.apps.addons.install', $this->paper), [
            'source' => 'modrinth', 'project' => 'LUCK', 'version' => 'v-new',
        ]);
        $this->runQueued();
        $steps = $this->agentRequests[0]['steps'];
        // Zależność już jest — tylko stary plik out, nowy in.
        $this->assertSame(['op' => 'delete', 'paths' => ['plugins/LuckPerms-5.3.0.jar']], $steps[0]);
        $this->assertCount(2, $steps);

        $this->actingAs($this->customer)->delete(route('panel.apps.addons.destroy', [$this->paper, $old]))->assertRedirect();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/files/delete') && $r['path'] === 'plugins/LuckPerms-5.3.0.jar');
        $this->assertDatabaseMissing('app_addons', ['id' => $old->id]);
    }

    public function test_obcy_nie_zainstaluje_niczego(): void
    {
        $this->fakeApis();
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($stranger)->post(route('panel.apps.addons.install', $this->paper), [
            'source' => 'modrinth', 'project' => 'LUCK', 'version' => 'v-new',
        ])->assertForbidden();
        $this->actingAs($stranger)->get(route('panel.apps.modpacks', $this->paper))->assertForbidden();
    }

    // --- cache ------------------------------------------------------------------------------

    public function test_katalog_idzie_z_cache_bez_ponownego_pytania_serwisu(): void
    {
        $this->fakeApis();
        $this->paper->forceFill(['minecraft' => ['platform' => 'paper', 'mc' => '1.20.1']])->save();

        $this->actingAs($this->customer)->get(route('panel.apps.addons', $this->paper))->assertOk()->assertSee('LuckPerms');
        $this->actingAs($this->customer)->get(route('panel.apps.addons.show', [$this->paper, 'modrinth', 'LUCK']))->assertOk();
        $count = count(Http::recorded());

        $this->actingAs($this->customer)->get(route('panel.apps.addons', $this->paper))->assertOk()->assertSee('LuckPerms');
        $this->actingAs($this->customer)->get(route('panel.apps.addons.show', [$this->paper, 'modrinth', 'LUCK']))->assertOk()->assertSee('5.4.1');
        $this->assertCount($count, Http::recorded());

        // Lista wersji bez changelogów — odpowiedź Modrinth jest wtedy wielokrotnie mniejsza.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/project/LUCK/version') && str_contains($r->url(), 'include_changelog=false'));
    }

    public function test_nieswieze_dane_sa_podawane_od_razu(): void
    {
        $this->fakeApis();
        $this->paper->forceFill(['minecraft' => ['platform' => 'paper', 'mc' => '1.20.1']])->save();
        $this->actingAs($this->customer)->get(route('panel.apps.addons', $this->paper))->assertOk();

        // Po czasie świeżości, ale w oknie nieświeżości: strona nie czeka na serwis
        // (który teraz by padł) — odświeżenie idzie w tle po odpowiedzi.
        $this->travel(2)->hours();
        Http::fake(['api.modrinth.com/*' => Http::response('awaria', 500), '*' => Http::response([])]);
        $this->actingAs($this->customer)->get(route('panel.apps.addons', $this->paper))->assertOk()->assertSee('LuckPerms');
    }

    public function test_ftb_pobiera_paczki_rownolegle_i_trzyma_je_w_cache(): void
    {
        $pack = fn (int $id) => ['id' => $id, 'name' => "Paczka {$id}", 'slug' => "p{$id}", 'synopsis' => 'x', 'installs' => $id,
            'description' => str_repeat('długi opis ', 500), 'art' => [['type' => 'square', 'url' => "https://cdn.test/{$id}.png"]], 'versions' => []];
        Http::fake([
            'api.feed-the-beast.com/v1/modpacks/public/modpack/popular/installs/100' => Http::response(['packs' => [1, 2, 3]]),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/1' => Http::response($pack(1)),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/2' => Http::response($pack(2)),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/3' => Http::response($pack(3)),
        ]);
        $ftb = app(\App\Domain\Apps\Content\Ftb::class);

        $first = $ftb->search('', 1);
        $this->assertSame(['Paczka 1', 'Paczka 2', 'Paczka 3'], array_column($first['items'], 'name'));
        $this->assertCount(4, Http::recorded());
        $this->assertSame($first, $ftb->search('', 1));
        $this->assertCount(4, Http::recorded());
        $this->assertArrayNotHasKey('description', $ftb->pack(2)); // w cache tylko potrzebne pola
    }

    public function test_rozgrzewanie_cache_katalogow(): void
    {
        $this->fakeApis([
            'api.feed-the-beast.com/*' => Http::response(['packs' => []]),
        ]);
        $this->paper->forceFill(['minecraft' => ['platform' => 'paper', 'mc' => '1.20.1']])->save();

        $this->artisan('virthub:warm-content', ['--top' => 1])->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'project_type:modpack'));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'project_type:plugin') && str_contains(urldecode($r->url()), 'versions:1.20.1'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'hangar.papermc.io'));

        // Klient trafia już w gotowy cache.
        $count = count(Http::recorded());
        $this->actingAs($this->customer)->get(route('panel.apps.addons', $this->paper))->assertOk()->assertSee('LuckPerms');
        $this->assertCount($count, Http::recorded());
    }

    public function test_nieznana_wersja_gry_nie_pyta_agenta_przy_kazdym_wejsciu(): void
    {
        $this->fakeApis(['node.test:8443/apps/*/files/*' => Http::response(['detail' => 'brak'], 404)]);

        $this->actingAs($this->customer)->get(route('panel.apps.addons', $this->paper))->assertOk()->assertSee('Nie znam wersji');
        $count = count(Http::recorded());
        $this->actingAs($this->customer)->get(route('panel.apps.addons', $this->paper))->assertOk();
        $this->assertCount($count, Http::recorded());
    }

    // --- postęp i potwierdzenia ------------------------------------------------------------------

    public function test_postep_instalacji_modpacka_z_etapem_wezla(): void
    {
        $this->fakeApis([
            'node.test:8443/jobs/agent-content-1' => Http::response(['job_id' => 'agent-content-1', 'status' => 'running',
                'stage' => 'download', 'progress' => 42, 'detail' => '120/240 · 310 MB']),
        ]);
        $this->actingAs($this->customer)->post(route('panel.apps.modpacks.install', $this->paper), [
            'source' => 'modrinth', 'project' => 'PACK', 'version' => 'pack-v1', 'eula' => '1',
        ]);
        $this->runQueued();

        $this->actingAs($this->customer)->get(route('panel.apps.show', $this->paper))
            ->assertOk()
            ->assertSee('id="progress"', false)
            ->assertSee('Pobieranie modów i konfiguracji')
            ->assertSee('progress-panel.js', false);

        $this->actingAs($this->customer)->getJson(route('panel.apps.status', $this->paper))
            ->assertOk()
            ->assertJsonPath('status', 'installing')
            ->assertJsonPath('job.action', 'modpack')
            ->assertJsonPath('job.stage', 'download')
            ->assertJsonPath('job.stage_progress', 42)
            ->assertJsonPath('job.stage_detail', '120/240 · 310 MB');
    }

    public function test_zakonczone_zadanie_bez_callbacku_konczy_postep(): void
    {
        $this->fakeApis([
            'node.test:8443/jobs/agent-content-1' => Http::response(['job_id' => 'agent-content-1', 'status' => 'done', 'result' => []]),
        ]);
        $this->actingAs($this->customer)->post(route('panel.apps.loader.install', $this->paper), ['loader' => 'fabric', 'mc' => '1.20.1', 'eula' => '1']);
        $this->runQueued();

        $this->actingAs($this->customer)->getJson(route('panel.apps.status', $this->paper))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('job.finished', true);
        $this->assertSame('fabric', $this->paper->fresh()->minecraft['platform']);
    }

    public function test_potwierdzenia_to_modale_panelu_a_nie_confirm(): void
    {
        $this->fakeApis();
        $html = $this->actingAs($this->customer)->get(route('panel.apps.settings', $this->paper))->assertOk()->getContent();
        $this->assertStringNotContainsString('confirm(', $html);
        $this->assertStringContainsString('data-confirm=', $html);
        $this->assertStringContainsString('vh-dialog.js', $html);
    }

    // --- modpacki i loadery ----------------------------------------------------------------------

    public function test_modpack_z_modrinth_przelacza_serwer_na_mody(): void
    {
        $this->fakeApis();
        AppAddon::query()->create([
            'app_server_id' => $this->paper->id, 'kind' => 'plugin', 'source' => 'modrinth', 'project_id' => 'LUCK',
            'version_id' => 'v-old', 'name' => 'LuckPerms', 'version' => '5.3.0', 'filename' => 'LuckPerms-5.3.0.jar',
        ]);

        $this->actingAs($this->customer)->post(route('panel.apps.modpacks.install', $this->paper), [
            'source' => 'modrinth', 'project' => 'PACK', 'version' => 'pack-v1',
        ])->assertSessionHasErrors('eula');

        $this->actingAs($this->customer)->post(route('panel.apps.modpacks.install', $this->paper), [
            'source' => 'modrinth', 'project' => 'PACK', 'version' => 'pack-v1', 'eula' => '1',
        ])->assertRedirect(route('panel.apps.show', $this->paper));

        $job = $this->runQueued();
        $app = $this->paper->fresh(['egg']);
        $this->assertSame('minecraft-modded', $app->egg->builtin_key);
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_17', $app->docker_image);
        $this->assertSame('true', $app->environment['EULA']);
        $this->assertTrue($app->isInstalling());

        $request = $this->agentRequests[0];
        $this->assertTrue($request['exclusive']);
        $this->assertSame('bash start.sh', $request['spec']['startup']);
        $ops = array_column($request['steps'], 'op');
        $this->assertSame(['delete', 'download', 'download', 'download', 'extract', 'write', 'write', 'write'], $ops);
        $this->assertStringContainsString('Pominięto 1 plików tylko dla klienta', $request['steps'][7]['label']);
        $this->assertContains('mods', $request['steps'][0]['paths']);
        $this->assertNotContains('@world', $request['steps'][0]['paths']);
        $this->assertNotContains('plugins', $request['steps'][0]['paths']);
        $paths = array_column($request['steps'], 'path');
        $this->assertContains('fabric-server-launch.jar', $paths);
        $this->assertContains('mods/lithium.jar', $paths);
        $this->assertNotContains('mods/sodium.jar', $paths); // tylko klient
        $this->assertSame(['overrides/' => '', 'server-overrides/' => ''], $request['steps'][4]['prefixes']);
        $this->assertSame(Loaders::startScript(), $request['steps'][5]['content']);

        $this->agentResult($job);
        $app->refresh();
        $this->assertTrue($app->isReady());
        $this->assertSame('fabric', $app->minecraft['platform']);
        $this->assertSame('1.20.1', $app->minecraft['mc']);
        $this->assertSame('Super Pack', $app->minecraft['modpack']['name']);
        $this->assertSame(0, $app->addons()->count());
        $this->assertSame('mod', (new \App\Domain\Apps\Content\ServerProfile($app))->addonKind());
    }

    public function test_czysty_forge_z_czyszczeniem_swiata(): void
    {
        $this->fakeApis();

        $this->actingAs($this->customer)->post(route('panel.apps.loader.install', $this->paper), [
            'loader' => 'forge', 'mc' => '1.20.1', 'wipe_world' => '1', 'wipe_plugins' => '1', 'eula' => '1',
        ])->assertRedirect();
        $this->runQueued();

        $steps = $this->agentRequests[0]['steps'];
        $this->assertContains('@world', $steps[0]['paths']);
        $this->assertContains('plugins', $steps[0]['paths']);
        $this->assertStringContainsString('razem ze światem', $steps[0]['label']);
        $java = collect($steps)->firstWhere('op', 'java');
        $this->assertSame(['-jar', 'forge-installer.jar', '--installServer'], $java['args']);
        $this->assertStringContainsString('forge-1.20.1-47.2.0-installer.jar', collect($steps)->firstWhere('path', 'forge-installer.jar')['url']);
    }

    public function test_reinstalacja_z_usunieciem_swiata_i_pluginow(): void
    {
        $specs = [];
        Http::fake(['node.test:8443/apps/*/reinstall' => function (Request $r) use (&$specs) {
            $specs[] = $r->data();

            return Http::response(['job_id' => 'agent-re'], 202);
        }]);
        AppAddon::query()->create([
            'app_server_id' => $this->paper->id, 'kind' => 'plugin', 'source' => 'modrinth', 'project_id' => 'LUCK',
            'version_id' => 'v-old', 'name' => 'LuckPerms', 'version' => '5.3.0', 'filename' => 'LuckPerms-5.3.0.jar',
        ]);
        $this->paper->forceFill(['minecraft' => ['platform' => 'paper', 'mc' => '1.20.1']])->save();

        $this->actingAs($this->customer)->get(route('panel.apps.settings', $this->paper))
            ->assertOk()->assertSee('name="wipe[]" value="world"', false)->assertSee('value="plugins"', false);

        $this->actingAs($this->customer)->post(route('panel.apps.reinstall', $this->paper), ['confirm' => '1', 'wipe' => ['nope']])
            ->assertSessionHasErrors('wipe.0');
        $this->actingAs($this->customer)->post(route('panel.apps.reinstall', $this->paper), ['confirm' => '1', 'wipe' => ['world', 'plugins']])
            ->assertRedirect(route('panel.apps.show', $this->paper));

        $job = AppJob::query()->latest('id')->firstOrFail();
        $this->assertSame(['wipe' => ['@world', 'plugins', 'mods']], $job->payload);
        (new InstallAppJob($job->id))->handle(app(AppJobApplier::class));
        $this->assertSame(['@world', 'plugins', 'mods'], $specs[0]['reinstall_wipe']);

        $this->agentResult($job);
        $app = $this->paper->fresh();
        $this->assertTrue($app->isReady());
        $this->assertSame(0, $app->addons()->count());
        $this->assertSame(['platform' => 'paper'], $app->minecraft);
    }

    public function test_reinstalacja_bez_opcji_niczego_nie_kasuje(): void
    {
        $specs = [];
        Http::fake(['node.test:8443/apps/*/reinstall' => function (Request $r) use (&$specs) {
            $specs[] = $r->data();

            return Http::response(['job_id' => 'agent-re'], 202);
        }]);
        $this->actingAs($this->customer)->post(route('panel.apps.reinstall', $this->paper), ['confirm' => '1', 'wipe' => ['all', 'world']]);
        $job = AppJob::query()->latest('id')->firstOrFail();
        $this->assertSame(['wipe' => ['*']], $job->payload); // całość i tak obejmuje świat

        $job->forceFill(['status' => AppJob::STATUS_DONE])->save();
        AppServer::query()->whereKey($this->paper->id)->update(['status' => AppServer::STATUS_READY]);
        $this->actingAs($this->customer)->post(route('panel.apps.reinstall', $this->paper), ['confirm' => '1'])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $job = AppJob::query()->latest('id')->firstOrFail();
        $this->assertNull($job->payload);
        (new InstallAppJob($job->id))->handle(app(AppJobApplier::class));
        $this->assertArrayNotHasKey('reinstall_wipe', $specs[0]);
    }

    public function test_starsza_wersja_gry_wymaga_usuniecia_swiata(): void
    {
        $this->fakeApis();
        $this->paper->forceFill(['minecraft' => ['platform' => 'paper', 'mc' => '26.2']])->save();

        $this->actingAs($this->customer)->post(route('panel.apps.loader.install', $this->paper), ['loader' => 'fabric', 'mc' => '1.20.1', 'eula' => '1']);
        $job = $this->runQueued();
        $this->assertSame(AppJob::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('Usuń też świat', $job->error);
        $this->assertTrue($this->paper->fresh()->isReady());
        $this->assertSame([], $this->agentRequests);

        $this->actingAs($this->customer)->post(route('panel.apps.loader.install', $this->paper), ['loader' => 'fabric', 'mc' => '1.20.1', 'eula' => '1', 'wipe_world' => '1']);
        $this->assertSame(AppJob::STATUS_RUNNING, $this->runQueued()->status);
    }

    public function test_nieudana_instalacja_modpacka_nie_psuje_aplikacji(): void
    {
        $this->fakeApis(['api.modrinth.com/v2/version/brak' => Http::response(['error' => 'not_found'], 404)]);

        $this->actingAs($this->customer)->post(route('panel.apps.modpacks.install', $this->paper), [
            'source' => 'modrinth', 'project' => 'PACK', 'version' => 'brak', 'eula' => '1',
        ]);
        $job = $this->runQueued();
        $this->assertSame(AppJob::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('nie znaleziono', $job->error);
        $this->assertTrue($this->paper->fresh()->isReady());
        $this->assertSame([], $this->agentRequests);

        $this->actingAs($this->customer)->get(route('panel.apps.modpacks', $this->paper))
            ->assertOk()->assertSee('Instalacja nie powiodła się');
    }

    public function test_druga_instalacja_czeka_na_pierwsza(): void
    {
        $this->fakeApis();
        $this->actingAs($this->customer)->post(route('panel.apps.loader.install', $this->paper), ['loader' => 'fabric', 'mc' => '1.20.1', 'eula' => '1']);
        $this->actingAs($this->customer)->post(route('panel.apps.loader.install', $this->paper), ['loader' => 'forge', 'mc' => '1.20.1', 'eula' => '1'])
            ->assertSessionHasErrors('content');
        $this->assertSame(1, AppJob::query()->whereIn('action', ContentManager::ACTIONS)->count());
    }

    public function test_modpacki_tylko_z_wersja_serwerowa(): void
    {
        config(['virthub.curseforge_api_key' => 'klucz']);
        $queries = [];
        Http::fake([
            'api.modrinth.com/v2/search*' => function (Request $r) use (&$queries) {
                $queries[] = $r->data();

                return Http::response(['total_hits' => 0, 'hits' => []]);
            },
            'api.modrinth.com/v2/project/CLIENT' => Http::response(['id' => 'CLIENT', 'title' => 'Shadery', 'project_type' => 'modpack', 'server_side' => 'unsupported']),
            'api.curseforge.com/v1/mods/search*' => Http::response(['data' => [
                ['id' => 1, 'name' => 'Z serwerem', 'latestFiles' => [['id' => 10, 'serverPackFileId' => 11]]],
                ['id' => 2, 'name' => 'Kliencki', 'latestFiles' => [['id' => 20, 'serverPackFileId' => null]]],
            ], 'pagination' => ['totalCount' => 2]]),
            'api.curseforge.com/v1/mods/1/files*' => Http::response(['data' => [
                ['id' => 10, 'modId' => 1, 'displayName' => 'v2', 'serverPackFileId' => 11],
                ['id' => 9, 'modId' => 1, 'displayName' => 'v1 (bez serwera)'],
            ]]),
        ]);
        $content = app(ContentManager::class);
        $profile = new \App\Domain\Apps\Content\ServerProfile($this->paper);

        $content->search('modpack', 'modrinth', '', 1, $profile);
        $this->assertContains(['server_side:required', 'server_side:optional'], json_decode($queries[0]['facets'], true));

        $cf = $content->search('modpack', 'curseforge', '', 1, $profile);
        $this->assertSame(['Z serwerem'], array_column($cf['items'], 'name'));
        $this->assertSame(['10'], array_column($content->versions('modpack', 'curseforge', '1', $profile), 'id'));

        $this->expectException(\App\Domain\Apps\Content\ContentException::class);
        $content->project('modpack', 'modrinth', 'CLIENT');
    }

    public function test_curseforge_bez_klucza_jest_ukryty(): void
    {
        config(['virthub.curseforge_api_key' => null]);
        $this->assertArrayNotHasKey('curseforge', app(ContentManager::class)->sources('modpack'));
        config(['virthub.curseforge_api_key' => 'klucz']);
        $this->assertArrayHasKey('curseforge', app(ContentManager::class)->sources('modpack'));
    }

    public function test_ftb_pomija_pliki_klienta(): void
    {
        Http::fake([
            'api.feed-the-beast.com/v1/modpacks/public/modpack/100/2000' => Http::response([
                'name' => '1.0', 'targets' => [
                    ['name' => 'minecraft', 'version' => '1.18.2', 'type' => 'game'],
                    ['name' => 'forge', 'version' => '40.2.34', 'type' => 'modloader'],
                ],
                'specs' => ['recommended' => 6144],
                'files' => [
                    ['path' => './mods', 'name' => 'a.jar', 'url' => 'https://files.feed-the-beast.com/a.jar', 'sha1' => str_repeat('a', 40), 'size' => 5, 'clientonly' => false],
                    ['path' => './mods', 'name' => 'oculus.jar', 'url' => 'https://files.feed-the-beast.com/o.jar', 'sha1' => str_repeat('b', 40), 'size' => 5, 'clientonly' => true],
                    ['path' => './config', 'name' => 'x.toml', 'url' => 'https://files.feed-the-beast.com/x.toml', 'sha1' => str_repeat('c', 40), 'size' => 5, 'clientonly' => false],
                ],
            ]),
            'api.feed-the-beast.com/v1/modpacks/public/modpack/100' => Http::response(['id' => 100, 'name' => 'StoneBlock 3', 'installs' => 1, 'art' => []]),
        ]);

        $plan = app(\App\Domain\Apps\Content\ModpackResolver::class)->resolve('ftb', '100', '2000');
        $this->assertSame(['mods/a.jar', 'config/x.toml'], array_column($plan['steps'], 'path'));
        $this->assertSame('forge', $plan['loader']);
        $this->assertSame(17, $plan['java']);
        $this->assertSame(6144, $plan['memory']);
    }

    public function test_dobor_javy(): void
    {
        $this->assertSame(8, Loaders::javaFor('1.12.2'));
        $this->assertSame(17, Loaders::javaFor('1.18.2'));
        $this->assertSame(17, Loaders::javaFor('1.20.4'));
        $this->assertSame(21, Loaders::javaFor('1.20.5'));
        $this->assertSame(21, Loaders::javaFor('1.21.1'));
        $this->assertSame(25, Loaders::javaFor('26.1'));
        $images = ['Java 21' => 'y:java_21', 'Java 8' => 'y:java_8', 'Java 17' => 'y:java_17'];
        $this->assertSame('y:java_17', Loaders::imageFor($images, 16));
        $this->assertSame('y:java_21', Loaders::imageFor($images, 25));
    }
}
