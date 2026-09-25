<?php

namespace Tests\Feature;

use App\Domain\Metrics\Traffic;
use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\ServerTraffic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TrafficTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo('2026-09-25 12:00:00');
        $this->owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->server = Server::factory()->create([
            'user_id' => $this->owner->id,
            'hypervisor_id' => Hypervisor::factory()->create()->id,
            'bandwidth_gb' => 10,
        ]);
        Http::fake(fn () => Http::response([
            'uuid' => $this->server->agent_uuid, 'state' => 'running', 'cpu_time_ns' => 1,
            'ram_used_mb' => 100, 'net_rx_bytes' => $this->counters[0], 'net_tx_bytes' => $this->counters[1],
        ]));
    }

    /** @var array{0: int, 1: int} liczniki, które „zwraca węzeł" */
    private array $counters = [0, 0];

    private function sample(int $rx, int $tx): void
    {
        $this->counters = [$rx, $tx];
        $this->artisan('virthub:collect-metrics')->assertSuccessful();
        $this->travel(1)->minutes();
    }

    private function traffic(): Traffic
    {
        return app(Traffic::class);
    }

    public function test_przyrosty_licznikow_i_restart_maszyny(): void
    {
        $this->sample(1_000, 2_000);            // pierwsza próbka — punkt odniesienia
        $this->sample(3_000_000_000, 1_000_002_000);
        $this->sample(500_000_000, 0);          // restart maszyny: liczniki od zera

        $usage = $this->traffic()->usage($this->server->fresh());
        $this->assertSame(2_999_999_000 + 500_000_000, $usage['rx']);
        $this->assertSame(1_000_000_000, $usage['tx']);
        $this->assertSame($usage['rx'] + $usage['tx'], $usage['used']);
        $this->assertSame(10 * Traffic::GB, $usage['limit']);
        $this->assertSame('2026-10-01', $usage['resets_at']);
    }

    public function test_przekroczenie_limitu_blokuje_maszyne(): void
    {
        $this->sample(0, 0);
        $this->sample(6 * Traffic::GB, 4 * Traffic::GB + 1);

        $server = $this->server->fresh();
        $this->assertTrue($server->isSuspended());
        $this->assertNotNull($server->traffic_blocked_at);
        $this->assertStringContainsString('Przekroczono limit transferu: 10 GB z 10 GB', $server->suspension_reason);
        $this->assertDatabaseHas('server_jobs', ['server_id' => $server->id, 'action' => 'power']);

        $this->actingAs($this->owner)->get(route('panel.servers.show', $server))
            ->assertSee('Przekroczono limit transferu')
            ->assertSee('10 GB');
    }

    public function test_nowy_miesiac_odblokowuje(): void
    {
        $this->sample(0, 0);
        $this->sample(11 * Traffic::GB, 0);
        $this->assertTrue($this->server->fresh()->isSuspended());

        $this->travelTo('2026-10-01 00:05:00');
        $this->artisan('virthub:collect-metrics')->assertSuccessful();

        $server = $this->server->fresh();
        $this->assertFalse($server->isSuspended());
        $this->assertNull($server->traffic_blocked_at);
        $this->assertSame(0, $this->traffic()->usage($server)['used']);
    }

    public function test_zawieszenie_za_platnosc_nie_jest_zdejmowane(): void
    {
        $this->server->forceFill([
            'traffic_blocked_at' => now(), 'suspended_at' => now(), 'suspension_reason' => 'Nieopłacona faktura.',
        ])->save();

        $this->traffic()->releaseEligible();

        $server = $this->server->fresh();
        $this->assertNull($server->traffic_blocked_at);
        $this->assertTrue($server->isSuspended(), 'blokada za płatność zostaje');
    }

    public function test_bez_limitu_nie_blokuje(): void
    {
        $this->server->forceFill(['bandwidth_gb' => 0])->save();
        $this->sample(0, 0);
        $this->sample(50 * Traffic::GB, 0);

        $this->assertFalse($this->server->fresh()->isSuspended());
        $this->assertNull($this->traffic()->usage($this->server->fresh())['limit']);
    }

    public function test_personel_zmienia_limit_i_zeruje_licznik(): void
    {
        $this->sample(0, 0);
        $this->sample(12 * Traffic::GB, 0);
        $this->assertTrue($this->server->fresh()->isSuspended());

        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $this->actingAs($this->owner)->post(route('panel.servers.traffic', $this->server), ['action' => 'reset'])->assertForbidden();

        $this->actingAs($support)->post(route('panel.servers.traffic', $this->server), [
            'action' => 'limit', 'bandwidth_gb' => 20,
        ])->assertSessionHasNoErrors();
        $this->assertFalse($this->server->fresh()->isSuspended(), 'większy limit odblokowuje od razu');

        $this->actingAs($support)->post(route('panel.servers.traffic', $this->server), ['action' => 'reset']);
        $this->assertSame(0, $this->traffic()->usage($this->server->fresh())['used']);
    }

    public function test_tylko_ruch_wychodzacy_gdy_tak_ustawiono(): void
    {
        config(['virthub.traffic_counting' => 'out']);
        ServerTraffic::create(['server_id' => $this->server->id, 'period_start' => '2026-09-01', 'rx_bytes' => 9 * Traffic::GB, 'tx_bytes' => 2 * Traffic::GB]);

        $usage = $this->traffic()->usage($this->server);
        $this->assertSame(2 * Traffic::GB, $usage['used']);
        $this->assertFalse($this->traffic()->enforce($this->server));
    }

    public function test_jednostki(): void
    {
        $this->assertSame('512 MB', Traffic::human(512_000_000));
        $this->assertSame('1,5 GB', Traffic::human(1_500_000_000));
        $this->assertSame('2 TB', Traffic::human(2_000_000_000_000));
        $this->assertSame('0 MB', Traffic::human(0));
        $this->assertSame('bez limitu', Traffic::human(null));
    }

    public function test_api_zwraca_transfer(): void
    {
        ServerTraffic::create(['server_id' => $this->server->id, 'period_start' => '2026-09-01', 'rx_bytes' => 100, 'tx_bytes' => 50]);

        $this->actingAs($this->owner)->getJson("/api/v1/servers/{$this->server->id}")
            ->assertJsonPath('data.traffic.used', 150)
            ->assertJsonPath('data.traffic.limit', 10 * Traffic::GB);
    }
}
