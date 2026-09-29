<?php

namespace Tests\Feature;

use App\Domain\Apps\AppJobApplier;
use App\Domain\Apps\EggImporter;
use App\Jobs\InstallAppJob;
use App\Models\AppEgg;
use App\Models\AppJob;
use App\Models\AppServer;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Migracja serwerów z panelu Pterodactyl. */
class PterodactylMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PTERO = 'https://ptero.example.com';

    private User $admin;

    private Hypervisor $node;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->node = Hypervisor::factory()->create([
            'agent_url' => 'https://node.test:8443', 'apps_enabled' => true, 'app_port_start' => 25565, 'app_port_end' => 25570,
            'ram_mb_total' => 32768, 'disk_gb_total' => 500,
            'last_health' => ['apps' => ['available' => true], 'public_ipv4' => '203.0.113.10'],
        ]);
        app(EggImporter::class)->importBuiltin();
        User::factory()->create(['email' => 'stary@example.com', 'role' => User::ROLE_CUSTOMER]);
    }

    private function server(string $id, string $name, string $email): array
    {
        return ['attributes' => [
            'id' => 1, 'identifier' => $id, 'name' => $name, 'nest' => 1, 'egg' => 5, 'suspended' => false,
            'limits' => ['memory' => 3072, 'disk' => 15000, 'cpu' => 200],
            'container' => ['image' => 'ghcr.io/pterodactyl/yolks:java_21', 'environment' => [
                'SERVER_JARFILE' => 'paper.jar', 'MINECRAFT_VERSION' => '1.21.1', 'P_SERVER_UUID' => 'x', 'STARTUP' => 'java',
            ]],
            'relationships' => [
                'user' => ['attributes' => ['email' => $email, 'username' => 'gracz', 'first_name' => 'Jan', 'last_name' => 'Nowak']],
                'egg' => ['attributes' => ['name' => 'Paper']],
                'allocations' => ['data' => [['attributes' => ['port' => 25565]], ['attributes' => ['port' => 25566]]]],
            ],
        ]];
    }

    private function fakePterodactyl(): void
    {
        Http::fake([
            self::PTERO.'/api/application/servers*' => Http::response(['data' => [
                $this->server('abcd1234', 'Survival', 'stary@example.com'),
                $this->server('efgh5678', 'Kreatywny', 'nowy@example.com'),
            ], 'meta' => ['pagination' => ['total_pages' => 1]]]),
            self::PTERO.'/api/application/nests/1/eggs/5*' => Http::response(['attributes' => [
                'uuid' => 'egg-uuid', 'name' => 'Paper', 'author' => 'parker@pterodactyl.io', 'description' => 'Paper',
                'docker_images' => ['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21'],
                'startup' => 'java -Xms128M -jar {{SERVER_JARFILE}}',
                'config' => ['files' => ['server.properties' => ['parser' => 'properties', 'find' => ['server-port' => '{{server.build.default.port}}']]],
                    'startup' => ['done' => ')! For help, type '], 'stop' => 'stop'],
                'script' => ['install' => 'echo install', 'container' => 'ghcr.io/pterodactyl/installers:alpine', 'entry' => 'ash'],
                'relationships' => ['variables' => ['data' => [
                    ['attributes' => ['name' => 'Jar', 'env_variable' => 'SERVER_JARFILE', 'default_value' => 'server.jar', 'user_viewable' => true, 'user_editable' => true, 'rules' => 'required|string|max:40']],
                    ['attributes' => ['name' => 'Wersja', 'env_variable' => 'MINECRAFT_VERSION', 'default_value' => 'latest', 'user_viewable' => true, 'user_editable' => true, 'rules' => 'nullable|string|max:20']],
                ]]],
            ]]),
            self::PTERO.'/api/client/servers/*/power' => Http::response([], 204),
            self::PTERO.'/api/client/servers/*/files/list*' => Http::response(['data' => [
                ['attributes' => ['name' => 'world']], ['attributes' => ['name' => 'server.properties']],
            ]]),
            self::PTERO.'/api/client/servers/*/files/compress' => Http::response(['attributes' => ['name' => 'archive-2026.tar.gz']]),
            self::PTERO.'/api/client/servers/*/files/download*' => Http::response(['attributes' => ['url' => 'https://wings.example.com:8080/download/file?token=abc']]),
            self::PTERO.'/api/client/servers/*/files/delete' => Http::response([], 204),
            'node.test:8443/*' => Http::response(['job_id' => 'agent-1'], 202),
        ]);
    }

    public function test_polaczenie_lista_i_przeniesienie_serwerow(): void
    {
        $this->fakePterodactyl();

        $this->actingAs($this->admin)->post(route('panel.admin.apps.pterodactyl.connect'), [
            'url' => self::PTERO.'/', 'application_key' => 'zly', 'client_key' => 'ptlc_x',
        ])->assertSessionHasErrors('application_key');
        $this->actingAs($this->admin)->post(route('panel.admin.apps.pterodactyl.connect'), [
            'url' => self::PTERO.'/', 'application_key' => 'ptla_app', 'client_key' => 'ptlc_client',
        ])->assertRedirect(route('panel.admin.apps.pterodactyl'));

        $this->actingAs($this->admin)->get(route('panel.admin.apps.pterodactyl'))->assertOk()
            ->assertSee('Survival')->assertSee('Kreatywny')->assertSee('nowy@example.com')->assertDontSee('ptlc_client');

        $this->actingAs($this->admin)->post(route('panel.admin.apps.pterodactyl.migrate'), [
            'servers' => ['abcd1234', 'efgh5678'], 'stop' => '1',
        ])->assertRedirect()->assertSessionHas('migration_created', fn ($created) => count($created) === 1 && $created[0]['email'] === 'nowy@example.com');

        $this->assertSame(2, AppServer::query()->count());
        $this->assertSame(1, AppEgg::query()->where('source', 'pterodactyl')->count(), 'egg importowany raz');
        $app = AppServer::query()->where('name', 'Survival')->sole();
        $this->assertSame('stary@example.com', $app->user->email);
        $this->assertSame([3072, 15000, 200], [$app->memory_mb, $app->disk_mb, $app->cpu_percent]);
        $this->assertSame(['SERVER_JARFILE' => 'paper.jar', 'MINECRAFT_VERSION' => '1.21.1'], $app->environment, 'tylko zmienne eggu');
        $this->assertSame(2, $app->allocations()->count());
        $this->assertTrue(User::query()->where('email', 'nowy@example.com')->exists());

        // Migrowany serwer znika z listy do zaznaczenia.
        $this->actingAs($this->admin)->get(route('panel.admin.apps.pterodactyl'))->assertSee('przeniesiony');

        // Zadanie instalacji: zatrzymanie, spakowanie, link z Wings w skrypcie zamiast skryptu eggu.
        $job = AppJob::query()->where('app_server_id', $app->id)->sole();
        $this->assertStringNotContainsString('ptlc_client', json_encode($job->payload), 'klucz zaszyfrowany');
        (new InstallAppJob($job->id))->handle(app(AppJobApplier::class));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/client/servers/abcd1234/power') && $r['signal'] === 'stop'
            && $r->hasHeader('Authorization', 'Bearer ptlc_client'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/files/compress') && $r['files'] === ['world', 'server.properties']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/apps')
            && str_contains($r['install']['script'], "'https://wings.example.com:8080/download/file?token=abc'")
            && str_contains($r['install']['script'], 'tar -xzf - -C /mnt/server')
            && ! str_contains($r['install']['script'], 'echo install'));
        $this->assertSame('archive-2026.tar.gz', $job->fresh()->payload['pterodactyl']['archive']);

        // Sukces: archiwum usunięte w Pterodactylu, klucz usunięty z zadania.
        app(AppJobApplier::class)->apply($job->fresh(), ['status' => 'done']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/files/delete') && $r['files'] === ['archive-2026.tar.gz']);
        $this->assertArrayNotHasKey('key', $job->fresh()->payload['pterodactyl']);
        $this->assertTrue($app->fresh()->isReady());
    }

    public function test_bledny_klucz_to_czytelny_komunikat(): void
    {
        Http::fake([self::PTERO.'/*' => Http::response(['errors' => [['detail' => 'Unauthenticated']]], 401)]);

        $this->actingAs($this->admin)->post(route('panel.admin.apps.pterodactyl.connect'), [
            'url' => self::PTERO, 'application_key' => 'ptla_x', 'client_key' => 'ptlc_x',
        ])->assertSessionHasErrors(['url' => 'Pterodactyl odrzucił klucz API (HTTP 401) — sprawdź klucz i jego uprawnienia.']);
    }

    public function test_tylko_personel_od_aplikacji(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($customer)->get(route('panel.admin.apps.pterodactyl'))->assertForbidden();
    }
}
