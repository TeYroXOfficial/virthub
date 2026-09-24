<?php

namespace Tests\Feature;

use App\Domain\Metrics\ServerMetrics;
use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->server = Server::factory()->create([
            'user_id' => $this->owner->id,
            'hypervisor_id' => Hypervisor::factory()->create()->id,
            'agent_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'vcpu' => 2,
            'ram_mb' => 4096,
        ]);
        $this->server->markState(ServerState::Running);
    }

    private function agentStats(array $overrides = []): array
    {
        return [
            'uuid' => $this->server->agent_uuid, 'state' => 'running',
            'cpu_time_ns' => 0, 'cpu_percent' => 0,
            'ram_used_mb' => 1024, 'ram_total_mb' => 4096,
            'disk_read_bytes' => 0, 'disk_write_bytes' => 0, 'net_rx_bytes' => 0, 'net_tx_bytes' => 0,
            ...$overrides,
        ];
    }

    // --- liczenie szybkości -------------------------------------------------

    public function test_szybkosc_z_roznicy_licznikow(): void
    {
        $rates = ServerMetrics::rates(
            ['cpu_time_ns' => 70e9, 'disk_read_bytes' => 6_000_000, 'disk_write_bytes' => 600, 'net_rx_bytes' => 1200, 'net_tx_bytes' => 60],
            ['cpu_time_ns' => 10e9, 'disk_read_bytes' => 0, 'disk_write_bytes' => 0, 'net_rx_bytes' => 0, 'net_tx_bytes' => 0],
            60,
            2,
        );

        // 60 s procesora w 60 s na 2 rdzeniach = 50 %.
        $this->assertSame(50.0, $rates['cpu_percent']);
        $this->assertSame(100_000, $rates['disk_read_bps']);
        $this->assertSame(10, $rates['disk_write_bps']);
        $this->assertSame(20, $rates['net_rx_bps']);
        $this->assertSame(1, $rates['net_tx_bps']);
    }

    public function test_wyzerowany_licznik_nie_daje_skoku(): void
    {
        // Restart maszyny zeruje liczniki — ujemna różnica to nie ruch.
        $rates = ServerMetrics::rates(
            ['cpu_time_ns' => 1e9, 'disk_read_bytes' => 10, 'disk_write_bytes' => 0, 'net_rx_bytes' => 0, 'net_tx_bytes' => 0],
            ['cpu_time_ns' => 500e9, 'disk_read_bytes' => 9e9, 'disk_write_bytes' => 0, 'net_rx_bytes' => 0, 'net_tx_bytes' => 0],
            60,
            1,
        );

        $this->assertSame(0.0, $rates['cpu_percent']);
        $this->assertSame(0, $rates['disk_read_bps']);
    }

    // --- zbieranie próbek ---------------------------------------------------

    public function test_zbieranie_zapisuje_szybkosci(): void
    {
        $this->freezeSecond();
        ServerMetric::create([
            'server_id' => $this->server->id,
            'sampled_at' => now()->subMinute(),
            'cpu_time_ns' => 10e9, 'disk_read_bytes' => 0, 'disk_write_bytes' => 0, 'net_rx_bytes' => 0, 'net_tx_bytes' => 0,
        ]);
        Http::fake(['*' => Http::response($this->agentStats([
            'cpu_time_ns' => 40e9, 'net_rx_bytes' => 6000,
        ]))]);

        $this->artisan('virthub:collect-metrics')->assertSuccessful();

        $sample = ServerMetric::latest('id')->first();
        $this->assertSame(25.0, (float) $sample->cpu_percent);
        $this->assertSame(100, (int) $sample->net_rx_bps);
        $this->assertSame(4096, (int) $sample->ram_total_mb);
    }

    // --- historia -----------------------------------------------------------

    public function test_historia_usrednia_w_kubelkach_i_zostawia_przerwy(): void
    {
        $this->travelTo(now()->startOfHour()->addMinutes(30));

        foreach ([[20, 10], [20, 30], [5, 80]] as [$minutesAgo, $cpu]) {
            ServerMetric::create([
                'server_id' => $this->server->id,
                'sampled_at' => now()->subMinutes($minutesAgo),
                'cpu_percent' => $cpu,
                'ram_used_mb' => 2048,
                'net_rx_bps' => 1000,
            ]);
        }

        $response = $this->actingAs($this->owner)
            ->getJson("/api/v1/servers/{$this->server->id}/metrics?range=hour")
            ->assertOk()
            ->assertJsonPath('range', 'hour')
            ->assertJsonPath('bucket_seconds', 60)
            ->assertJsonPath('samples', 3);

        $series = $response->json('series');
        $this->assertCount(60, $series['t']);

        $cpu = array_values(array_filter($series['cpu_percent'], fn ($v) => $v !== null));
        $this->assertEquals([20, 80], $cpu, 'Dwie próbki z tej samej minuty dają średnią');
        $this->assertGreaterThan(50, count(array_filter($series['cpu_percent'], fn ($v) => $v === null)),
            'Minuty bez próbek to przerwy, a nie zera');
    }

    public function test_zakresy_doba_i_tydzien(): void
    {
        foreach (['day' => [600, 144], 'week' => [3600, 168]] as $range => [$bucket, $points]) {
            $this->actingAs($this->owner)
                ->getJson("/api/v1/servers/{$this->server->id}/metrics?range={$range}")
                ->assertOk()
                ->assertJsonPath('bucket_seconds', $bucket)
                ->assertJsonCount($points, 'series.t');
        }

        $this->actingAs($this->owner)
            ->getJson("/api/v1/servers/{$this->server->id}/metrics?range=year")
            ->assertStatus(422);
    }

    public function test_obcy_nie_widzi_statystyk(): void
    {
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($stranger)->getJson("/api/v1/servers/{$this->server->id}/metrics")->assertForbidden();
        $this->actingAs($stranger)->getJson("/api/v1/servers/{$this->server->id}/metrics/live")->assertForbidden();
    }

    // --- na żywo --------------------------------------------------------------

    public function test_podglad_na_zywo_liczy_z_poprzedniego_odczytu(): void
    {
        Http::fakeSequence()
            ->push($this->agentStats(['net_tx_bytes' => 0]))
            ->push($this->agentStats(['net_tx_bytes' => 3000]));

        $first = $this->actingAs($this->owner)->getJson("/api/v1/servers/{$this->server->id}/metrics/live")
            ->assertOk()
            ->assertJsonPath('ram_used_mb', 1024)
            ->assertJsonPath('ram_total_mb', 4096);
        $this->assertSame(0, $first->json('net_tx_bps'));

        $this->travel(3)->seconds();
        $second = $this->actingAs($this->owner)->getJson("/api/v1/servers/{$this->server->id}/metrics/live")->assertOk();

        $this->assertGreaterThan(0, $second->json('net_tx_bps'));
    }

    public function test_zatrzymana_maszyna_nie_ma_podgladu(): void
    {
        $this->server->markState(ServerState::Stopped);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/servers/{$this->server->id}/metrics/live")
            ->assertStatus(409);
    }

    public function test_niedostepny_wezel_to_503(): void
    {
        Http::fake(['*' => Http::response(['detail' => 'awaria'], 500)]);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/servers/{$this->server->id}/metrics/live")
            ->assertStatus(503);
    }

    public function test_strona_maszyny_ma_wykresy(): void
    {
        $this->actingAs($this->owner)
            ->get(route('panel.servers.show', $this->server))
            ->assertOk()
            ->assertSee('data-server-metrics', false)
            ->assertSee('uPlot.iife.min.js', false)
            ->assertSee('Historia zużycia');
    }
}
