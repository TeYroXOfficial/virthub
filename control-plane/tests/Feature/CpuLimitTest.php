<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\ServerProvisioner;
use App\Enums\ServerState;
use App\Jobs\RunServerActionJob;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Twardy limit procesora: pakiet, zmiana przez personel i ładunek dla agenta. */
class CpuLimitTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->server = Server::factory()->create([
            'hypervisor_id' => Hypervisor::factory()->create()->id,
            'vcpu' => 2,
        ]);
    }

    public function test_administrator_ustawia_limit_w_pakiecie(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('panel.admin.packages.store'), [
            'name' => 'Ograniczony', 'vcpu' => 2, 'cpu_limit_percent' => 150,
            'ram_mb' => 2048, 'disk_gb' => 20, 'bandwidth_gb' => 1000, 'ip_count' => 1, 'ipv6_count' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertSame(150, VpsPackage::where('name', 'Ograniczony')->value('cpu_limit_percent'));

        // Więcej niż vCPU × 100 nie ma sensu — maszyna i tak nie zużyje.
        $this->actingAs($admin)->post(route('panel.admin.packages.store'), [
            'name' => 'Za dużo', 'vcpu' => 2, 'cpu_limit_percent' => 250,
            'ram_mb' => 2048, 'disk_gb' => 20, 'bandwidth_gb' => 1000, 'ip_count' => 1, 'ipv6_count' => 0,
        ])->assertSessionHasErrors('cpu_limit_percent');
    }

    public function test_limit_trafia_do_agenta_przy_tworzeniu(): void
    {
        $this->server->update(['cpu_limit_percent' => 80]);

        $this->assertSame(80, ServerPayload::forCreate($this->server)['cpu_limit_percent']);
    }

    public function test_personel_zmienia_limit_na_zywo(): void
    {
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);

        $this->actingAs($support)
            ->post(route('panel.servers.cpu-limit', $this->server), ['cpu_limit_percent' => 50])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $job = ServerJob::where('action', 'cpu_limit')->sole();
        $this->assertSame(['cpu_limit_percent' => 50], $job->payload);
        Queue::assertPushed(RunServerActionJob::class);

        Http::fake(['*' => Http::response(['job_id' => 'agent-1', 'status' => 'queued'], 202)]);
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), "/vm/{$this->server->agent_uuid}/cpu-limit")
            && $r['cpu_limit_percent'] === 50);

        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'done', 'result' => []]);
        $this->assertSame(50, $this->server->fresh()->cpu_limit_percent);
        $this->assertSame(ServerState::Running, $this->server->fresh()->state, 'limit nie zatrzymuje maszyny');
    }

    public function test_puste_pole_zdejmuje_limit_a_za_duzy_jest_odrzucany(): void
    {
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $this->server->update(['cpu_limit_percent' => 100]);

        $this->actingAs($support)
            ->post(route('panel.servers.cpu-limit', $this->server), ['cpu_limit_percent' => 201])
            ->assertSessionHasErrors('cpu_limit_percent');

        $this->actingAs($support)->post(route('panel.servers.cpu-limit', $this->server), ['cpu_limit_percent' => '']);
        $this->assertSame(['cpu_limit_percent' => null], ServerJob::where('action', 'cpu_limit')->sole()->payload);
    }

    public function test_klient_nie_zmienia_limitu(): void
    {
        $this->actingAs($this->server->user)
            ->post(route('panel.servers.cpu-limit', $this->server), ['cpu_limit_percent' => null])
            ->assertForbidden();

        $this->actingAs($this->server->user)
            ->get(route('panel.servers.show', $this->server))
            ->assertDontSee('id="cpu-limit"', false);
    }

    public function test_zmiana_pakietu_przenosi_limit_pakietu(): void
    {
        $this->server->forceFill(['state' => ServerState::Stopped, 'cpu_limit_percent' => 50])->save();
        $package = VpsPackage::factory()->create(['vcpu' => 4, 'cpu_limit_percent' => 300, 'disk_gb' => $this->server->disk_gb]);

        $job = app(ServerProvisioner::class)->resize($this->server, $package);
        $this->assertSame(300, $job->payload['cpu_limit_percent']);

        app(AgentResultApplier::class)->apply($job, ['status' => 'done', 'result' => []]);
        $this->assertSame(300, $this->server->fresh()->cpu_limit_percent);
    }
}
