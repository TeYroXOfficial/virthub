<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\GuestOs;
use App\Models\Hypervisor;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GuestOsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->server = Server::factory()->create([
            'user_id' => $this->owner->id,
            'hypervisor_id' => Hypervisor::factory()->create([
                'last_health' => ['cpu_model' => 'AMD EPYC 7763 64-Core Processor'],
            ])->id,
            'os_template_id' => OsTemplate::factory()->create(['name' => 'Ubuntu 24.04 LTS'])->id,
        ]);
    }

    public function test_system_odczytany_z_maszyny_zastepuje_szablon(): void
    {
        $this->assertSame('ubuntu', $this->server->osFamily());
        $this->assertFalse($this->server->osDetected());

        Http::fake(['*/os' => Http::response([
            'id' => 'debian', 'pretty_name' => 'Debian GNU/Linux 13 (trixie)', 'version' => '13', 'source' => 'os-release',
        ])]);

        $this->actingAs($this->owner)->post(route('panel.servers.detect-os', $this->server))
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Debian GNU/Linux 13'));

        $server = $this->server->fresh();
        $this->assertSame('debian', $server->osFamily());
        $this->assertSame('Debian GNU/Linux 13 (trixie)', $server->osLabel());

        $this->actingAs($this->owner)->get(route('panel.servers.show', $server))
            ->assertSee('Debian GNU/Linux 13 (trixie)')
            ->assertSee('odczytany z maszyny')
            ->assertSee('AMD EPYC 7763 64-Core Processor');

        $this->actingAs($this->owner)->getJson("/api/v1/servers/{$server->id}")
            ->assertJsonPath('data.guest_os.id', 'debian')
            ->assertJsonPath('data.cpu_model', 'AMD EPYC 7763 64-Core Processor');
    }

    public function test_windows_i_nieznane_systemy(): void
    {
        $this->assertSame('windows', GuestOs::familyFor('mswindows'));
        $this->assertSame('rocky', GuestOs::familyFor('rocky'));
        $this->assertSame('linux', GuestOs::familyFor('slackware'));
        $this->assertNull(GuestOs::familyFor(null));
    }

    public function test_nieudany_odczyt_zostawia_poprzedni(): void
    {
        $this->server->forceFill(['guest_os_id' => 'ubuntu', 'guest_os_name' => 'Ubuntu 24.04.1 LTS'])->save();
        Http::fake(['*/os' => Http::response(['detail' => 'qemu-guest-agent nie odpowiada'], 409)]);

        $this->assertFalse(app(GuestOs::class)->detect($this->server->fresh()));
        $this->assertSame('Ubuntu 24.04.1 LTS', $this->server->fresh()->guest_os_name);
        $this->assertNotNull($this->server->fresh()->guest_os_checked_at, 'kolejna próba dopiero za kilka godzin');
    }

    public function test_zbieranie_metryk_odczytuje_system_raz_na_kilka_godzin(): void
    {
        Http::fake([
            '*/stats' => Http::response(['uuid' => 'x', 'state' => 'running', 'cpu_time_ns' => 1, 'memory_used_mb' => 100]),
            '*/os' => Http::response(['id' => 'ubuntu', 'pretty_name' => 'Ubuntu 24.04.1 LTS', 'version' => '24.04']),
        ]);

        $this->artisan('virthub:collect-metrics')->assertSuccessful();
        $this->artisan('virthub:collect-metrics')->assertSuccessful();

        $this->assertSame('Ubuntu 24.04.1 LTS', $this->server->fresh()->guest_os_name);
        Http::assertSentCount(3); // 2× stats + 1× os
    }

    public function test_reinstalacja_kasuje_stary_odczyt(): void
    {
        $this->server->forceFill(['guest_os_id' => 'debian', 'guest_os_name' => 'Debian 12', 'guest_os_checked_at' => now()])->save();
        $job = ServerJob::create(['server_id' => $this->server->id, 'action' => 'rebuild', 'status' => 'running']);

        app(AgentResultApplier::class)->apply($job, ['status' => 'done', 'result' => ['state' => 'running']]);

        $server = $this->server->fresh();
        $this->assertNull($server->guest_os_name);
        $this->assertNull($server->guest_os_checked_at, 'nowy system odczytamy przy najbliższym obiegu');
    }

    public function test_procesor_ustawiany_w_ustawieniach_wezla(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $node = $this->server->hypervisor;
        $this->assertSame('AMD EPYC 7763 64-Core Processor', $node->cpuModel());

        $this->actingAs($admin)->put(route('panel.admin.hypervisors.update', $node), [
            'cpu_model' => 'AMD EPYC 9654 (Genoa)', 'cpu_cores_total' => 64, 'ram_mb_total' => 262144,
            'disk_gb_total' => 4000, 'bridge' => 'br0', 'status' => 'online', 'accepts_new_servers' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertSame('AMD EPYC 9654 (Genoa)', $node->fresh()->cpuModel());
        $this->actingAs($this->owner)->get(route('panel.servers.show', $this->server))->assertSee('AMD EPYC 9654 (Genoa)');

        // Puste pole = znów wykryty przez węzeł.
        $this->actingAs($admin)->put(route('panel.admin.hypervisors.update', $node), [
            'cpu_model' => '', 'cpu_cores_total' => 64, 'ram_mb_total' => 262144,
            'disk_gb_total' => 4000, 'bridge' => 'br0', 'status' => 'online', 'accepts_new_servers' => 1,
        ]);
        $this->assertSame('AMD EPYC 7763 64-Core Processor', $node->fresh()->cpuModel());
    }
}
