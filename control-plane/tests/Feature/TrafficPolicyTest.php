<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Domain\Metrics\Traffic;
use App\Jobs\RunServerActionJob;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrafficPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private array $counters = [0, 0];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo('2026-09-25 12:00:00');
        $package = VpsPackage::factory()->create(['traffic_limit_action' => 'throttle', 'traffic_throttle_mbps' => 5, 'port_speed_mbps' => 1000]);
        $this->server = Server::factory()->create(['vps_package_id' => $package->id, 'hypervisor_id' => Hypervisor::factory()->create()->id, 'bandwidth_gb' => 10]);
        Http::fake(fn () => Http::response([
            'uuid' => $this->server->agent_uuid, 'state' => 'running', 'cpu_time_ns' => 1,
            'ram_used_mb' => 100, 'net_rx_bytes' => $this->counters[0], 'net_tx_bytes' => $this->counters[1],
        ]));
    }

    private function sample(int $rx, int $tx): void
    {
        $this->counters = [$rx, $tx];
        $this->artisan('virthub:collect-metrics')->assertSuccessful();
        $this->travel(1)->minutes();
    }

    public function test_dziedziczenie_polityki(): void
    {
        $node = $this->server->hypervisor;
        $node->forceFill(['traffic_counting' => 'out', 'traffic_limit_action' => 'block'])->save();
        $this->assertSame(['counting' => 'out', 'action' => 'throttle', 'throttle_mbps' => 5], Traffic::policy($this->server->fresh()));
        $this->server->forceFill(['traffic_limit_action' => 'block', 'traffic_counting' => 'in'])->save();
        $this->assertSame(['counting' => 'in', 'action' => 'block', 'throttle_mbps' => 5], Traffic::policy($this->server->fresh()));
        $this->assertSame(1000, Traffic::bandwidthMbps($this->server->fresh()), 'Prędkość portu z pakietu');
        $this->assertSame(1000, ServerPayload::forCreate($this->server->fresh())['bandwidth_mbps']);
    }

    public function test_dlawienie_zamiast_blokady_i_zdjecie_po_pakiecie(): void
    {
        $hook = Webhook::create(['name' => 'H', 'url' => 'https://hooks.example.test/x', 'events' => ['server.traffic_throttled', 'server.traffic_unthrottled'], 'secret' => 's', 'is_active' => true]);
        $this->sample(0, 0);
        $this->sample(8 * Traffic::GB, 3 * Traffic::GB);

        $server = $this->server->fresh();
        $this->assertFalse($server->isSuspended(), 'Dławienie, nie zawieszenie');
        $this->assertNotNull($server->traffic_throttled_at);
        $this->assertSame(5, Traffic::bandwidthMbps($server));
        $job = ServerJob::where('action', 'network')->latest('id')->firstOrFail();
        Http::fake(['*' => Http::response(['job_id' => 'n1', 'status' => 'queued'], 202)]);
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/network') && $r['bandwidth_mbps'] === 5);
        $this->assertDatabaseHas('webhook_deliveries', ['webhook_id' => $hook->id, 'event' => 'server.traffic_throttled']);

        // Dokupione 5 GB — limit 15 GB > 11 GB zużycia: dławienie zdjęte, wraca prędkość portu.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->post(route('panel.servers.traffic', $server), ['action' => 'block', 'gb' => 5, 'note' => 'FV/1'])->assertSessionHasNoErrors();
        $server = $server->fresh();
        $this->assertNull($server->traffic_throttled_at);
        $this->assertSame(15 * Traffic::GB, app(Traffic::class)->usage($server)['limit']);
        $this->assertSame(1000, Traffic::bandwidthMbps($server));
        $this->assertDatabaseHas('webhook_deliveries', ['webhook_id' => $hook->id, 'event' => 'server.traffic_unthrottled']);
        $this->get(route('panel.servers.show', $server))->assertSee('+5 GB')->assertSee('FV/1');

        // Nowy okres: pakiet z poprzedniego miesiąca nie liczy się do limitu.
        $this->travelTo('2026-10-02 00:10:00');
        $this->assertSame(10 * Traffic::GB, app(Traffic::class)->usage($server->fresh())['limit']);
    }

    public function test_jednostki_binarne_i_api(): void
    {
        config(['virthub.traffic_units' => 'binary']);
        $this->assertSame(10 * 1024 ** 3, app(Traffic::class)->usage($this->server->fresh())['limit']);
        $this->assertSame('1 GiB', Traffic::human(1024 ** 3));

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), ['admin']);
        $base = "/api/v1/admin/servers/{$this->server->id}/traffic";
        $this->getJson($base)->assertOk()->assertJsonPath('data.units', 'binary')->assertJsonPath('data.action', 'throttle');
        $this->putJson($base, ['limit_action' => 'block', 'counting' => 'out', 'port_speed_mbps' => 200])->assertOk()
            ->assertJsonPath('data.action', 'block')->assertJsonPath('data.counting', 'out')->assertJsonPath('data.port_speed_mbps', 200);
        $this->assertSame(1, ServerJob::where('action', 'network')->count(), 'Nowa prędkość portu trafia na węzeł');
        $block = $this->postJson("$base/blocks", ['gb' => 50, 'note' => 'API'])->assertCreated()->assertJsonPath('data.extra_gb', 50)->json('data.blocks.0.id');
        $this->deleteJson("$base/blocks/$block")->assertOk()->assertJsonPath('data.extra_gb', 0);
    }
}
