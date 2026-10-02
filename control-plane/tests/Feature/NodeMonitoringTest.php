<?php

namespace Tests\Feature;

use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\EggImporter;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Strona węzła z aplikacjami, grupy hypervisorów i monitorowanie węzła. */
class NodeMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Hypervisor $node;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->node = Hypervisor::factory()->create([
            'name' => 'node-1', 'enrolled_at' => now(), 'apps_enabled' => true,
            'app_port_start' => 25565, 'app_port_end' => 25570, 'ram_mb_total' => 16384, 'disk_gb_total' => 200,
            'last_health' => ['apps' => ['available' => true], 'public_ipv4' => '203.0.113.5'],
        ]);
    }

    private function app(): \App\Models\AppServer
    {
        app(EggImporter::class)->importBuiltin();
        $plan = AppPlan::query()->create(['name' => 'S', 'memory_mb' => 2048, 'cpu_percent' => 100, 'disk_mb' => 4096, 'ports' => 1]);

        return app(AppProvisioner::class)->order(
            User::factory()->create(['email' => 'gracz@example.com']),
            AppEgg::query()->where('builtin_key', 'minecraft-paper')->firstOrFail(), $plan, 'Survival', node: $this->node,
        );
    }

    public function test_strona_wezla_pokazuje_aplikacje(): void
    {
        $app = $this->app();

        $this->actingAs($this->admin)->get(route('panel.admin.hypervisors.show', $this->node))
            ->assertOk()
            ->assertSee('Aplikacje (1)')
            ->assertSee('Survival')
            ->assertSee(route('panel.apps.show', $app), false)
            ->assertSee('w tym aplikacje: 2048 MB')
            ->assertSee(route('panel.admin.monitoring', ['node' => $this->node->id]), false);

        $this->actingAs($this->admin)->get(route('panel.admin.hypervisors'))->assertOk()->assertDontSee('Utwórz grupę');
    }

    public function test_osobna_strona_grup(): void
    {
        $this->actingAs($this->admin)->get(route('panel.admin.hypervisor-groups'))
            ->assertOk()->assertSee('Utwórz grupę')->assertSee('node-1');

        $this->actingAs($this->admin)->from(route('panel.admin.hypervisor-groups'))
            ->post(route('panel.admin.hypervisor-groups.store'), ['name' => 'Warszawa', 'hypervisors' => [$this->node->id]])
            ->assertRedirect(route('panel.admin.hypervisor-groups'));
        $this->assertSame('Warszawa', HypervisorGroup::query()->first()?->name);

        $this->actingAs($this->admin)->get(route('panel.admin.hypervisor-groups'))->assertSee('Warszawa');
    }

    public function test_monitorowanie_dokleja_nazwy_uslug_z_panelu(): void
    {
        $app = $this->app();
        $server = Server::factory()->create(['hypervisor_id' => $this->node->id, 'hostname' => 'vps.example.com']);

        Http::fake(['*/system/monitor' => Http::response([
            'interval' => 5.0,
            'cpu' => ['usage' => 12.5, 'steal' => 1.0, 'cores' => 2, 'per_core' => [10, 15]],
            'services' => [
                ['kind' => 'kvm', 'server_id' => $server->id, 'app_uuid' => null, 'ref' => (string) $server->id, 'cpu_pct' => 80.0],
                ['kind' => 'app', 'server_id' => null, 'app_uuid' => $app->uuid, 'ref' => 'abc123', 'cpu_pct' => 20.0],
                ['kind' => 'lxc', 'server_id' => 999, 'app_uuid' => null, 'ref' => 'virthub-999', 'cpu_pct' => 1.0],
            ],
            'processes' => [
                ['pid' => 10, 'name' => 'qemu-system-x86', 'cpu_pct' => 80.0, 'owner' => ['kind' => 'kvm', 'ref' => (string) $server->id, 'server_id' => $server->id]],
                ['pid' => 1, 'name' => 'systemd', 'cpu_pct' => 0.0, 'owner' => null],
            ],
        ])]);

        $this->actingAs($this->admin)->get(route('panel.admin.monitoring'))
            ->assertOk()->assertSee('node-1')->assertSee(route('panel.admin.monitoring.data', $this->node), false)
            ->assertSee('js/monitoring.js', false);

        $json = $this->actingAs($this->admin)->getJson(route('panel.admin.monitoring.data', $this->node))->assertOk()->json();

        $this->assertSame('vps.example.com', $json['services'][0]['name']);
        $this->assertSame(route('panel.servers.show', $server), $json['services'][0]['url']);
        $this->assertSame('Survival', $json['services'][1]['name']);
        $this->assertSame('gracz@example.com', $json['services'][1]['customer']);
        $this->assertFalse($json['services'][2]['known']);
        $this->assertSame('vps.example.com', $json['processes'][0]['owner']['name']);
        $this->assertNull($json['processes'][1]['owner']);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/system/monitor') && $r->hasHeader('X-VH-Signature'));
    }

    public function test_monitorowanie_niedostepnego_wezla_i_uprawnienia(): void
    {
        Http::fake(['*' => Http::response('awaria', 500)]);
        $this->actingAs($this->admin)->getJson(route('panel.admin.monitoring.data', $this->node))->assertStatus(502)->assertJsonStructure(['error']);

        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $this->actingAs($support)->get(route('panel.admin.monitoring'))->assertForbidden();
        $this->actingAs($support)->getJson(route('panel.admin.monitoring.data', $this->node))->assertForbidden();
    }
}
