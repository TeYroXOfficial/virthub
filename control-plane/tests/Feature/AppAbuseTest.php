<?php

namespace Tests\Feature;

use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\EggImporter;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Ochrona aplikacji: zgłoszenia nadużyć z węzła, zawieszenie, zwolnienie, import eggów. */
class AppAbuseTest extends TestCase
{
    use RefreshDatabase;

    private Hypervisor $node;

    private User $customer;

    private AppServer $appServer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->node = Hypervisor::factory()->create([
            'apps_enabled' => true,
            'app_port_start' => 25565,
            'app_port_end' => 25574,
            'ram_mb_total' => 16384,
            'disk_gb_total' => 500,
            'last_health' => ['apps' => ['available' => true], 'public_ipv4' => '203.0.113.10'],
        ]);
        $this->customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        app(EggImporter::class)->importBuiltin();
        $plan = AppPlan::query()->create(['name' => 'S', 'memory_mb' => 1024, 'cpu_percent' => 100, 'disk_mb' => 2048, 'ports' => 1]);
        $this->appServer = app(AppProvisioner::class)->order(
            $this->customer, AppEgg::query()->where('builtin_key', 'minecraft-paper')->firstOrFail(), $plan, 'Survival',
        );
        $this->appServer->update(['status' => AppServer::STATUS_READY]);
    }

    private function report(array $body, ?string $secret = null): TestResponse
    {
        $json = json_encode($body);
        $ts = (string) time();
        $sig = hash_hmac('sha256', implode("\n", [$ts, 'POST', '/api/internal/agent/app-abuse', hash('sha256', $json)]), $secret ?? $this->node->callback_secret);

        return $this->call('POST', '/api/internal/agent/app-abuse', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_VH_TIMESTAMP' => $ts,
            'HTTP_X_VH_SIGNATURE' => $sig,
        ], $json);
    }

    private function prootFinding(): array
    {
        return ['category' => 'vm', 'level' => 'block', 'source' => 'process', 'detail' => '/home/container/proot -r ubuntu /bin/bash'];
    }

    public function test_wykryty_proot_zawiesza_aplikacje(): void
    {
        $this->report(['uuid' => $this->appServer->uuid, 'action' => 'killed', 'findings' => [$this->prootFinding()]])
            ->assertOk()
            ->assertJson(['suspended' => true]);

        $app = $this->appServer->fresh();
        $this->assertTrue($app->isSuspended());
        $this->assertStringContainsString('PteroVM/proot/QEMU', $app->suspension_reason);
        $this->assertSame('killed', $app->abuse_findings['action']);
        $this->assertNotNull($app->abuse_detected_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'app.abuse']);

        // Klient widzi powód, a konsola/zasilanie są zablokowane zawieszeniem.
        $this->actingAs($this->customer)->get(route('panel.apps.show', $app))
            ->assertOk()
            ->assertSee('Automatyczna blokada');
        $this->actingAs($this->customer)->postJson(route('panel.apps.power', $app), ['action' => 'start'])->assertStatus(409);
    }

    public function test_tunel_jest_tylko_zglaszany(): void
    {
        $this->report(['uuid' => $this->appServer->uuid, 'action' => 'reported', 'findings' => [
            ['category' => 'tunnel', 'level' => 'warn', 'source' => 'process', 'detail' => 'ngrok http 8080'],
        ]])->assertOk()->assertJson(['suspended' => false]);

        $app = $this->appServer->fresh();
        $this->assertFalse($app->isSuspended());
        $this->assertNotNull($app->abuse_detected_at);
    }

    public function test_bez_automatycznego_zawieszania_tylko_zapis(): void
    {
        config(['virthub.apps_abuse_suspend' => false]);

        $this->report(['uuid' => $this->appServer->uuid, 'action' => 'killed', 'findings' => [$this->prootFinding()]])
            ->assertOk()->assertJson(['suspended' => false]);
        $this->assertFalse($this->appServer->fresh()->isSuspended());
    }

    public function test_zgloszenie_z_cudzym_podpisem_i_smieci_sa_odrzucane(): void
    {
        $other = Hypervisor::factory()->create();
        $this->report(['uuid' => $this->appServer->uuid, 'action' => 'killed', 'findings' => [$this->prootFinding()]], $other->callback_secret)
            ->assertStatus(401);
        $this->report(['uuid' => $this->appServer->uuid, 'action' => 'killed', 'findings' => [['category' => 'cokolwiek']]])
            ->assertStatus(422);
        $this->report(['uuid' => $this->appServer->uuid, 'action' => 'rm -rf', 'findings' => [$this->prootFinding()]])
            ->assertStatus(422);

        $this->assertFalse($this->appServer->fresh()->isSuspended());
    }

    public function test_administrator_zwalnia_z_ochrony_po_falszywym_alarmie(): void
    {
        Http::fake(['*' => Http::response(['uuid' => $this->appServer->uuid, 'restart_required' => false])]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('panel.admin.apps.abuse-exempt', $this->appServer))->assertRedirect();
        $this->assertTrue($this->appServer->fresh()->abuse_exempt);
        // Węzeł dostaje zwolnienie w specyfikacji.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/apps/'.$this->appServer->uuid) && $r['guard_exempt'] === true);

        // Zgłoszenia zwolnionej aplikacji są ignorowane.
        $this->report(['uuid' => $this->appServer->uuid, 'action' => 'killed', 'findings' => [$this->prootFinding()]])
            ->assertOk()->assertJson(['ignored' => true]);
        $this->assertFalse($this->appServer->fresh()->isSuspended());

        // Klient nie może sam wyłączyć ochrony.
        $this->actingAs($this->customer)->post(route('panel.admin.apps.abuse-exempt', $this->appServer))->assertForbidden();
    }

    public function test_ustawienia_pokazuja_znaleziska_personelowi(): void
    {
        $this->report(['uuid' => $this->appServer->uuid, 'action' => 'blocked', 'findings' => [
            ['category' => 'miner', 'level' => 'block', 'source' => 'file', 'detail' => '/tools/xmrig'],
        ]]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('panel.apps.settings', $this->appServer))
            ->assertOk()
            ->assertSee('/tools/xmrig')
            ->assertSee('koparka kryptowalut');
        $this->actingAs($admin)->get(route('panel.admin.apps'))->assertOk()->assertSee('nadużycie');
    }

    public function test_egg_vps_nie_da_sie_zaimportowac(): void
    {
        $egg = [
            'meta' => ['version' => 'PTDL_v2'],
            'name' => 'Free VPS',
            'startup' => 'bash /entrypoint.sh',
            'docker_images' => ['Ubuntu' => 'ghcr.io/example/vps:latest'],
            'scripts' => ['installation' => ['script' => "curl -Lo proot https://example.test/proot\nchmod +x proot", 'container' => 'alpine', 'entrypoint' => 'ash']],
        ];

        try {
            app(EggImporter::class)->import($egg);
            $this->fail('Egg VPS nie powinien przejść importu.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('PteroVM', $e->getMessage());
        }

        $egg['name'] = 'Serwer';
        $egg['scripts']['installation']['script'] = 'wget https://example.test/xmrig';
        $this->expectException(ValidationException::class);
        app(EggImporter::class)->import($egg);
    }

    public function test_wbudowane_eggi_przechodza_kontrole(): void
    {
        foreach (AppEgg::all() as $egg) {
            $this->assertNull(EggImporter::forbiddenReason($egg->name, $egg->startup, $egg->install_script, $egg->images(), $egg->description), $egg->name);
        }
    }
}
