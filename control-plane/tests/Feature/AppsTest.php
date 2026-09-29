<?php

namespace Tests\Feature;

use App\Domain\Apps\AppJobApplier;
use App\Domain\Apps\AppPayload;
use App\Domain\Apps\AppPorts;
use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\EggImporter;
use App\Domain\Network\IpPoolManager;
use App\Jobs\InstallAppJob;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Aplikacje (serwery gier, boty): eggi, zamówienie, porty, instalacja, konsola i pliki. */
class AppsTest extends TestCase
{
    use RefreshDatabase;

    private Hypervisor $node;

    private User $customer;

    private AppPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->node = Hypervisor::factory()->create([
            'apps_enabled' => true,
            'app_port_start' => 25565,
            'app_port_end' => 25574,
            'ram_mb_total' => 16384,
            'ram_mb_used' => 0,
            'disk_gb_total' => 500,
            'disk_gb_used' => 0,
            'last_health' => ['apps' => ['available' => true, 'docker_version' => '29.0'], 'public_ipv4' => '203.0.113.10'],
        ]);
        $this->customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->plan = AppPlan::query()->create(['name' => 'Gra S', 'memory_mb' => 2048, 'cpu_percent' => 100, 'disk_mb' => 10240, 'ports' => 2]);
        app(EggImporter::class)->importBuiltin();
    }

    private function paper(): AppEgg
    {
        return AppEgg::query()->where('builtin_key', 'minecraft-paper')->firstOrFail();
    }

    private function order(?AppEgg $egg = null): AppServer
    {
        return app(AppProvisioner::class)->order($this->customer, $egg ?? $this->paper(), $this->plan, 'Survival');
    }

    public function test_overcommit_pamieci_i_powod_braku_miejsca(): void
    {
        $this->node->update(['ram_mb_total' => 4096]);
        $this->order();
        $this->order();

        try {
            $this->order();
            $this->fail('Trzecia aplikacja nie powinna się zmieścić.');
        } catch (\DomainException $e) {
            $this->assertStringNotContainsString('RAM', $e->getMessage()); // klient nie widzi szczegółów węzłów
        }
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        try {
            app(AppProvisioner::class)->order($admin, $this->paper(), $this->plan, 'Admin');
            $this->fail('Nie powinno się zmieścić.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('za mało RAM: wolne 0 MB, plan 2048 MB', $e->getMessage());
        }

        $this->actingAs($admin)->put(route('panel.admin.hypervisors.update', $this->node), [
            'cpu_cores_total' => $this->node->cpu_cores_total, 'ram_mb_total' => 4096, 'disk_gb_total' => 500,
            'bridge' => $this->node->bridge, 'status' => $this->node->status, 'apps_enabled' => '1',
            'app_port_start' => 25565, 'app_port_end' => 25574, 'app_memory_overcommit' => 150,
        ])->assertSessionHasNoErrors();
        $this->assertSame(150, $this->node->fresh()->app_memory_overcommit);
        $this->order(); // 6144 MB z 4096 × 150%
        $this->assertSame(6144, app(AppProvisioner::class)->appMemoryAllocated($this->node));
        $this->actingAs($admin)->get(route('panel.admin.hypervisors.show', $this->node))->assertOk()->assertSee('przydzielone 6144 z 6144 MB');
    }

    public function test_wbudowane_szablony_po_angielsku(): void
    {
        $this->customer->forceFill(['locale' => 'en'])->save();
        $app = $this->order();
        $this->actingAs($this->customer)->get(route('panel.apps.create'))->assertOk()
            ->assertSee('Discord bot: Node.js')->assertSee('A high-performance Minecraft server')->assertDontSee('Wydajny serwer Minecraft');
        $this->actingAs($this->customer)->get(route('panel.apps.startup', $app))->assertOk()
            ->assertSee('Server file')->assertSee('Minecraft EULA acceptance')->assertDontSee('Nazwa pliku .jar');

        // Szablon wgrany przez administratora zostaje w oryginale.
        $custom = AppEgg::query()->create(['name' => 'Gałąź', 'description' => 'Mój opis', 'startup' => 'x', 'docker_images' => ['a' => 'ghcr.io/a:b']]);
        $this->assertSame('Gałąź', $custom->displayName());
    }

    public function test_admin_widzi_maszyny_i_aplikacje_w_jednym_spisie(): void
    {
        $app = $this->order();
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'inny@example.com']);
        \App\Models\Server::factory()->create(['user_id' => $other->id, 'hostname' => 'vps-klienta.example.com', 'hypervisor_id' => $this->node->id]);
        \App\Models\Server::factory()->create(['user_id' => $other->id, 'hostname' => 'kontener.example.com', 'virtualization' => 'lxc']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('panel.admin.services'))->assertOk()
            ->assertSee('Survival')->assertSee('vps-klienta.example.com')->assertSee('kontener.example.com')
            ->assertSee(route('panel.admin.services'), false);

        $this->actingAs($admin)->get(route('panel.admin.services', ['kind' => 'app']))->assertOk()
            ->assertSee('Survival')->assertDontSee('vps-klienta.example.com');
        $this->actingAs($admin)->get(route('panel.admin.services', ['kind' => 'lxc']))->assertOk()
            ->assertSee('kontener.example.com')->assertDontSee('vps-klienta.example.com')->assertDontSee('Survival');
        $this->actingAs($admin)->get(route('panel.admin.services', ['q' => 'inny@']))->assertOk()
            ->assertSee('vps-klienta.example.com')->assertDontSee('Survival');
        $this->actingAs($admin)->get(route('panel.admin.services', ['node' => $this->node->id]))->assertOk()
            ->assertSee('Survival')->assertSee('vps-klienta.example.com')->assertDontSee('kontener.example.com');

        // Support tylko z działem maszyn nie widzi aplikacji; bez obu działów — brak dostępu.
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT, 'permissions' => ['admin.servers']]);
        $this->actingAs($support)->get(route('panel.admin.services'))->assertOk()
            ->assertSee('vps-klienta.example.com')->assertDontSee($app->name);
        $this->actingAs($this->customer)->get(route('panel.admin.services'))->assertForbidden();
    }

    // --- eggi -------------------------------------------------------------------------------

    public function test_wbudowane_eggi_sa_zaimportowane_z_konfiguracja(): void
    {
        $this->assertSame(5, AppEgg::count());
        $paper = $this->paper();
        $this->assertSame('game', $paper->category);
        $this->assertSame('stop', $paper->stop_command);
        $this->assertArrayHasKey('Java 21', $paper->images());
        $this->assertSame('server.properties', $paper->config_files[0]['file']);
        $this->assertStringContainsString('fill.papermc.io/v3', $paper->install_script);

        // Ponowny import odświeża zamiast dublować.
        app(EggImporter::class)->importBuiltin();
        $this->assertSame(5, AppEgg::count());
    }

    public function test_import_eggu_pterodactyla_v1_i_odrzucenie_smieci(): void
    {
        $egg = app(EggImporter::class)->import([
            'meta' => ['version' => 'PTDL_v1'],
            'name' => 'Terraria Vanilla',
            'image' => 'ghcr.io/parkervcp/yolks:debian',
            'startup' => './TerrariaServer.bin.x86_64 -port {{SERVER_PORT}}',
            'config' => [
                'files' => json_encode(['serverconfig.txt' => ['parser' => 'file', 'find' => ['port' => 'port={{server.build.default.port}}', 'cond' => ['a' => 'b']]]]),
                'startup' => json_encode(['done' => ['Type \'help\'']]),
                'stop' => 'exit',
            ],
            'scripts' => ['installation' => ['script' => "#!/bin/bash\r\necho hi\r\n", 'container' => 'debian:bookworm-slim', 'entrypoint' => 'bash']],
            'variables' => [
                ['name' => 'Wersja', 'env_variable' => 'TERRARIA_VERSION', 'default_value' => 'latest', 'user_viewable' => 1, 'user_editable' => 1, 'rules' => 'required|string|max:20'],
                ['name' => 'Zła', 'env_variable' => '1BAD', 'default_value' => ''],
            ],
        ]);

        $this->assertSame(['ghcr.io/parkervcp/yolks:debian' => 'ghcr.io/parkervcp/yolks:debian'], $egg->images());
        $this->assertSame('exit', $egg->stop_command);
        $this->assertSame("Type 'help'", $egg->startup_done);
        $this->assertSame("#!/bin/bash\necho hi\n", $egg->install_script, 'CRLF → LF');
        $this->assertSame([['file' => 'serverconfig.txt', 'parser' => 'file', 'find' => ['port' => 'port={{server.build.default.port}}']]], $egg->config_files);
        $this->assertCount(1, $egg->variables, 'zmienna z niepoprawną nazwą odrzucona');

        $this->expectException(ValidationException::class);
        app(EggImporter::class)->import(['name' => 'nie egg']);
    }

    // --- zamówienie i porty --------------------------------------------------------------

    public function test_zamowienie_przydziela_wezel_porty_i_zleca_instalacje(): void
    {
        $app = $this->order();

        $this->assertSame($this->node->id, $app->hypervisor_id);
        $this->assertSame(AppServer::STATUS_INSTALLING, $app->status);
        $this->assertSame([25565, 25566], $app->allocations->pluck('port')->all());
        $this->assertTrue($app->allocations->first()->is_primary);
        $this->assertSame('203.0.113.10:25565', $app->address());
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_25', $app->docker_image, 'pierwszy obraz eggu jest domyślny');
        Queue::assertPushed(InstallAppJob::class);
        $this->assertDatabaseHas('app_jobs', ['app_server_id' => $app->id, 'action' => 'install']);
    }

    public function test_porty_nat_maszyn_sa_pomijane_i_konczy_sie_pula(): void
    {
        app(IpPoolManager::class)->create([
            'name' => 'NAT', 'prefix' => 24, 'hypervisor_id' => $this->node->id, 'type' => 'nat',
            'cidr' => '10.77.0.0/24', 'gateway' => '10.77.0.1', 'range_from' => '10.77.0.2', 'range_to' => '10.77.0.2',
            // adres .2 → blok 25567–25568, w środku zakresu aplikacji
            'nat_port_start' => 25563, 'nat_ports_per_server' => 2,
        ]);

        $this->assertSame([25565, 25566], $this->order()->allocations->pluck('port')->all());
        $this->assertSame([25569, 25570], $this->order()->allocations->pluck('port')->all(), 'blok NAT 25567–25568 pominięty');
        $this->order();
        $this->order(); // 25573, 25574 — pula wyczerpana

        $this->expectException(\DomainException::class);
        $this->order();
    }

    public function test_brak_dockera_albo_pamieci_blokuje_zamowienie(): void
    {
        $this->node->forceFill(['last_health' => ['apps' => ['available' => false]]])->save();
        try {
            $this->order();
            $this->fail('Węzeł bez Dockera przyjął aplikację');
        } catch (\DomainException) {
        }

        $this->node->forceFill(['last_health' => ['apps' => ['available' => true]], 'ram_mb_used' => 15000])->save();
        $this->expectException(\DomainException::class);
        $this->order();
    }

    public function test_klient_zamawia_przez_formularz(): void
    {
        $this->actingAs($this->customer)->get(route('panel.apps.create'))
            ->assertOk()->assertSee('Minecraft: Paper')->assertSee('Gra S');

        $this->actingAs($this->customer)->post(route('panel.apps.store'), [
            'egg' => $this->paper()->id, 'plan' => $this->plan->id, 'name' => 'Mój serwer',
            'image' => 'ghcr.io/pterodactyl/yolks:java_21',
        ])->assertRedirect();

        $app = AppServer::query()->sole();
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_21', $app->docker_image);
        $this->assertSame($this->customer->id, $app->user_id);
    }

    // --- specyfikacja dla agenta i instalacja ------------------------------------------

    public function test_specyfikacja_dla_agenta(): void
    {
        $app = $this->order();
        $app->update(['environment' => ['EULA' => 'true']]);
        $spec = AppPayload::spec($app->fresh());

        $this->assertSame($app->uuid, $spec['uuid']);
        $this->assertSame(2048, $spec['memory_mb']);
        $this->assertSame(100, $spec['cpu_percent']);
        $this->assertSame([['port' => 25565], ['port' => 25566]], $spec['allocations']);
        $this->assertSame('true', $spec['environment']['EULA']);
        $this->assertSame('latest', $spec['environment']['MINECRAFT_VERSION'], 'domyślna wartość zmiennej');
        $this->assertSame(
            ['server-ip' => '0.0.0.0', 'server-port' => '25565', 'query.port' => '25565'],
            $spec['config_files'][0]['replace'],
        );
        $this->assertSame('ghcr.io/pterodactyl/installers:alpine', $spec['install']['image']);
        $this->assertStringContainsString('-jar server.jar', AppPayload::startupPreview($app->fresh()));
    }

    public function test_instalacja_przez_agenta_i_callback(): void
    {
        $app = $this->order();
        $job = AppJob::query()->sole();
        Http::fake(['*' => Http::response(['job_id' => 'agent-app-1', 'status' => 'queued'], 202)]);

        (new InstallAppJob($job->id))->handle(app(AppJobApplier::class));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/apps')
            && $r['uuid'] === $app->uuid && $r['allocations'][0]['port'] === 25565);
        $this->assertSame('agent-app-1', $job->fresh()->agent_job_id);

        // Callback z węzła (podpisany sekretem hypervisora).
        $body = json_encode(['job_id' => 'agent-app-1', 'status' => 'done', 'result' => ['installed' => true]]);
        $ts = (string) time();
        $sig = hash_hmac('sha256', implode("\n", [$ts, 'POST', '/api/internal/agent/job-result', hash('sha256', $body)]), $this->node->callback_secret);
        $this->call('POST', '/api/internal/agent/job-result', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_VH_TIMESTAMP' => $ts, 'HTTP_X_VH_SIGNATURE' => $sig,
        ], $body)->assertOk();

        $this->assertSame(AppServer::STATUS_READY, $app->fresh()->status);
        $this->assertNotNull($app->fresh()->installed_at);
    }

    public function test_nieudana_instalacja_pokazuje_powod(): void
    {
        $app = $this->order();
        app(AppJobApplier::class)->apply(AppJob::query()->sole(), ['status' => 'failed', 'error' => 'Skrypt instalacyjny zakończył się kodem 1']);

        $this->assertSame(AppServer::STATUS_INSTALL_FAILED, $app->fresh()->status);
        $this->actingAs($this->customer)->get(route('panel.apps.show', $app))
            ->assertOk()->assertSee('Instalacja nie powiodła się')->assertSee('kodem 1');
    }

    // --- konsola, zasilanie, pliki ----------------------------------------------------------

    private function readyApp(): AppServer
    {
        $app = $this->order();
        $app->update(['status' => AppServer::STATUS_READY]);

        return $app->fresh();
    }

    public function test_zasilanie_konsola_i_logi_ida_do_agenta(): void
    {
        $app = $this->readyApp();
        Http::fake([
            '*/power' => Http::response(['state' => 'running']),
            '*/command' => Http::response(['sent' => true]),
            '*/logs' => Http::response(['source' => 'console', 'lines' => ['[Server] Done'], 'cursor' => 1.5]),
            '*/status' => Http::response(['state' => 'running', 'cpu_percent' => 12.5, 'memory_bytes' => 1024]),
            '*' => Http::response(['uuid' => $app->uuid, 'restart_required' => false]),
        ]);

        $this->actingAs($this->customer)->postJson(route('panel.apps.power', $app), ['action' => 'start'])
            ->assertOk()->assertJson(['state' => 'running']);
        $this->actingAs($this->customer)->postJson(route('panel.apps.command', $app), ['command' => 'say hej'])
            ->assertOk();
        $this->actingAs($this->customer)->postJson(route('panel.apps.command', $app), ['command' => "a\nb"])
            ->assertStatus(422);
        $this->actingAs($this->customer)->getJson(route('panel.apps.logs', $app).'?since=1.5')
            ->assertOk()->assertJson(['lines' => ['[Server] Done']]);
        $this->actingAs($this->customer)->getJson(route('panel.apps.status', $app))
            ->assertOk()->assertJson(['state' => 'running', 'cpu_percent' => 12.5]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/apps/{$app->uuid}/command") && $r['command'] === 'say hej');
        // Start najpierw wysyła aktualną specyfikację (egg, zmienne, zasoby).
        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
        $this->assertSame(["PUT /apps/{$app->uuid}", "POST /apps/{$app->uuid}/power"], array_slice($sent, 0, 2));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/apps/{$app->uuid}/logs") && $r['since'] === 1.5);
    }

    public function test_cudza_aplikacja_jest_niedostepna(): void
    {
        $app = $this->readyApp();
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($stranger)->get(route('panel.apps.show', $app))->assertForbidden();
        $this->actingAs($stranger)->postJson(route('panel.apps.power', $app), ['action' => 'kill'])->assertForbidden();
        $this->actingAs($stranger)->get(route('panel.apps.files', $app))->assertForbidden();
    }

    public function test_zawieszona_aplikacja_nie_przyjmuje_polecen(): void
    {
        $app = $this->readyApp();
        Http::fake(['*' => Http::response(['state' => 'offline'])]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('panel.admin.apps.suspend', $app), ['reason' => 'Brak płatności'])->assertRedirect();
        $this->assertTrue($app->fresh()->isSuspended());

        $this->actingAs($this->customer)->postJson(route('panel.apps.power', $app), ['action' => 'start'])
            ->assertStatus(409)->assertJsonFragment(['message' => 'Aplikacja jest zawieszona. Skontaktuj się z obsługą.']);
    }

    public function test_menedzer_plikow(): void
    {
        $app = $this->readyApp();
        Http::fake([
            '*/files/list' => Http::response(['path' => '', 'entries' => [
                ['name' => 'plugins', 'directory' => true, 'symlink' => false, 'size' => 4096, 'modified' => time(), 'mode' => 'drwxr-xr-x'],
                ['name' => 'server.properties', 'directory' => false, 'symlink' => false, 'size' => 120, 'modified' => time(), 'mode' => '-rw-r--r--'],
            ]]),
            '*/files/read' => Http::response(['content_base64' => base64_encode("motd=Hej\n")]),
            '*/files/write' => Http::response(['size' => 9]),
            '*/files/delete' => Http::response(['deleted' => 1]),
        ]);

        $this->actingAs($this->customer)->get(route('panel.apps.files', $app))
            ->assertOk()->assertSee('server.properties')->assertSee('plugins');
        $this->actingAs($this->customer)->get(route('panel.apps.files.edit', [$app, 'path' => 'server.properties']))
            ->assertOk()->assertSee('motd=Hej');
        $this->actingAs($this->customer)->put(route('panel.apps.files.save', $app), ['path' => 'server.properties', 'content' => "motd=Nowy\r\n"])
            ->assertRedirect();
        $this->actingAs($this->customer)->post(route('panel.apps.files.delete', $app), ['path' => 'plugins', 'names' => ['../old.jar']])
            ->assertRedirect();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/files/write')
            && $r['path'] === 'server.properties' && base64_decode($r['content_base64']) === "motd=Nowy\n");
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/files/delete') && $r['paths'] === ['plugins/old.jar'],
            'nazwa pliku sprowadzona do basename — bez wyjścia z katalogu');
    }

    public function test_duzy_plik_idzie_na_wezel_kawalkami(): void
    {
        $app = $this->readyApp();
        $parts = [];
        Http::fake(['*/files/write' => function (Request $r) use (&$parts) {
            $parts[] = [$r['path'], strlen(base64_decode($r['content_base64'])), $r['append'] ?? false];

            return Http::response(['size' => 1]);
        }]);
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('world.zip', str_repeat('x', 9 * 1024 * 1024));

        $this->actingAs($this->customer)->post(route('panel.apps.files.upload', $app), ['path' => 'saves', 'files' => [$file]])
            ->assertSessionHasNoErrors();

        // 9 MB → 4 + 4 + 1 MB; każde żądanie mieści się w limicie nginx węzła.
        $this->assertSame([
            ['saves/world.zip', 4194304, false],
            ['saves/world.zip', 4194304, true],
            ['saves/world.zip', 1048576, true],
        ], $parts);
    }

    public function test_zmienne_walidowane_regulami_eggu(): void
    {
        $app = $this->readyApp();
        Http::fake(['*' => Http::response(['uuid' => $app->uuid, 'restart_required' => true])]);

        $this->actingAs($this->customer)->put(route('panel.apps.startup.update', $app), [
            'variables' => ['SERVER_JARFILE' => '../../etc/passwd'],
        ])->assertSessionHasErrors('SERVER_JARFILE');

        $this->actingAs($this->customer)->put(route('panel.apps.startup.update', $app), [
            'image' => 'ghcr.io/pterodactyl/yolks:java_17',
            'variables' => ['EULA' => 'true', 'MINECRAFT_VERSION' => '1.21.11'],
        ])->assertSessionHasNoErrors();

        $app->refresh();
        $this->assertSame('ghcr.io/pterodactyl/yolks:java_17', $app->docker_image);
        $this->assertSame('1.21.11', $app->variableValues()['MINECRAFT_VERSION']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['environment']['EULA'] === 'true');

        $this->actingAs($this->customer)->put(route('panel.apps.startup.update', $app), ['image' => 'evil/image:latest'])
            ->assertSessionHasErrors('image');
    }

    public function test_usuniecie_kasuje_na_wezle_i_zwalnia_porty(): void
    {
        $app = $this->readyApp();
        Http::fake(['*' => Http::response(['deleted' => true])]);

        $this->actingAs($this->customer)->delete(route('panel.apps.destroy', $app), ['confirm' => '1'])->assertRedirect(route('panel.apps.index'));

        $this->assertModelMissing($app);
        $this->assertSame(0, \App\Models\AppAllocation::query()->whereNotNull('app_server_id')->count());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), "/apps/{$app->uuid}"));
        $this->assertSame(10, app(AppPorts::class)->freeCount($this->node->fresh()));
    }

    // --- administracja ----------------------------------------------------------------------

    public function test_administrator_zarzadza_planami_eggami_i_wezlem(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('panel.admin.apps.plans.store'), [
            'name' => 'Bot', 'memory_mb' => 512, 'disk_mb' => 2048, 'ports' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('app_plans', ['name' => 'Bot', 'cpu_percent' => 0]);

        $this->actingAs($admin)->get(route('panel.admin.apps.eggs'))->assertOk()->assertSee('Bot Discord: Python');
        $this->actingAs($admin)->get(route('panel.admin.apps'))->assertOk();

        $this->actingAs($admin)->put(route('panel.admin.hypervisors.update', $this->node), [
            'name' => $this->node->name, 'cpu_cores_total' => 8, 'ram_mb_total' => 16384, 'disk_gb_total' => 500,
            'bridge' => 'br0', 'status' => 'online', 'apps_enabled' => '1', 'app_port_start' => 30000, 'app_port_end' => 30100,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->node->fresh()->apps_enabled);
        $this->assertSame(30100, $this->node->fresh()->app_port_end);
    }

    public function test_bez_uprawnienia_klient_nie_widzi_aplikacji(): void
    {
        $limited = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'permissions' => ['servers.order']]);

        $this->actingAs($limited)->get(route('panel.apps.create'))->assertForbidden();
        $this->actingAs($limited)->get(route('panel.admin.apps'))->assertForbidden();
    }
}
