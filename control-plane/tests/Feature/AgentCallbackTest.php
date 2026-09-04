<?php

namespace Tests\Feature;

use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\ServerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Callback jest jedynym wejściem, przez które hypervisor zmienia stan w panelu,
 * i jedynym publicznym endpointem bez sesji — dlatego jego ochrona ma tu
 * najwięcej testów.
 */
class AgentCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sekret-callbacku-do-testow';

    private Hypervisor $hypervisor;

    private Server $server;

    private ServerJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hypervisor = Hypervisor::factory()->create(['callback_secret' => self::SECRET]);
        $this->server = Server::factory()->building()->create([
            'hypervisor_id' => $this->hypervisor->id,
            'vcpu' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 50,
        ]);
        $this->job = ServerJob::create([
            'server_id' => $this->server->id,
            'hypervisor_id' => $this->hypervisor->id,
            'action' => 'create',
            'status' => ServerJob::STATUS_RUNNING,
            'agent_job_id' => 'agent-job-1',
        ]);
    }

    private function send(array $payload, ?string $secret = null, ?int $timestamp = null)
    {
        $body = json_encode($payload);
        $timestamp ??= time();
        $canonical = implode("\n", [
            (string) $timestamp,
            'POST',
            '/api/internal/agent/job-result',
            hash('sha256', $body),
        ]);

        return $this->call(
            'POST',
            '/api/internal/agent/job-result',
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_VH_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_VH_SIGNATURE' => hash_hmac('sha256', $canonical, $secret ?? self::SECRET),
            ],
            $body,
        );
    }

    public function test_poprawny_callback_konczy_provisioning(): void
    {
        $this->send([
            'job_id' => 'agent-job-1',
            'action' => 'create_vm',
            'status' => 'done',
            'result' => [
                'uuid' => '11111111-2222-3333-4444-555555555555',
                'mac' => '52:54:00:00:00:07',
                'vnc_port' => 5901,
                'vnc_password' => 'tajne',
                'state' => 'running',
            ],
        ])->assertOk();

        $this->server->refresh();
        $this->assertSame(ServerState::Running, $this->server->state);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $this->server->agent_uuid);
        $this->assertSame(5901, $this->server->vnc_port);
        $this->assertSame(100, $this->server->build_progress);
        $this->assertSame(ServerJob::STATUS_DONE, $this->job->fresh()->status);
    }

    public function test_zly_podpis_nie_zmienia_niczego(): void
    {
        $this->send([
            'job_id' => 'agent-job-1',
            'status' => 'done',
            'result' => ['uuid' => 'x', 'state' => 'running'],
        ], secret: 'nieprawidlowy-sekret')->assertStatus(401);

        $this->assertSame(ServerState::Building, $this->server->fresh()->state);
        $this->assertSame(ServerJob::STATUS_RUNNING, $this->job->fresh()->status);
    }

    public function test_brak_naglowkow_podpisu_konczy_sie_401(): void
    {
        $this->postJson('/api/internal/agent/job-result', [
            'job_id' => 'agent-job-1',
            'status' => 'done',
        ])->assertStatus(401);
    }

    public function test_stary_znacznik_czasu_jest_odrzucany(): void
    {
        // Ochrona przed odtworzeniem przechwyconego wcześniej żądania.
        $this->send([
            'job_id' => 'agent-job-1',
            'status' => 'done',
            'result' => ['uuid' => 'x', 'state' => 'running'],
        ], timestamp: time() - 3600)->assertStatus(401);

        $this->assertSame(ServerState::Building, $this->server->fresh()->state);
    }

    public function test_sekret_jednego_wezla_nie_dziala_na_zadanie_drugiego(): void
    {
        $other = Hypervisor::factory()->create(['callback_secret' => 'sekret-innego-wezla']);

        $this->send([
            'job_id' => 'agent-job-1',
            'status' => 'done',
            'result' => ['uuid' => 'x', 'state' => 'running'],
        ], secret: 'sekret-innego-wezla')->assertStatus(401);

        $this->assertSame(ServerState::Building, $this->server->fresh()->state);
    }

    public function test_nieznane_zadanie_konczy_sie_404(): void
    {
        $this->send(['job_id' => 'nie-ma-takiego', 'status' => 'done'])->assertStatus(404);
    }

    public function test_nieudany_provisioning_zwalnia_zasoby(): void
    {
        $pool = IpPool::factory()->create(['hypervisor_id' => $this->hypervisor->id]);
        $address = IpAddress::factory()->create([
            'ip_pool_id' => $pool->id,
            'hypervisor_id' => $this->hypervisor->id,
            'server_id' => $this->server->id,
            'is_primary' => true,
        ]);

        $this->hypervisor->forceFill([
            'cpu_cores_used' => 2,
            'ram_mb_used' => 4096,
            'disk_gb_used' => 50,
        ])->save();

        $this->send([
            'job_id' => 'agent-job-1',
            'status' => 'failed',
            'error' => 'Nie znaleziono szablonu ubuntu-24.04.qcow2.',
        ])->assertOk();

        $this->server->refresh();
        $this->assertSame(ServerState::Error, $this->server->state);
        $this->assertStringContainsString('Nie znaleziono szablonu', $this->server->state_message);

        $this->hypervisor->refresh();
        $this->assertSame(0, $this->hypervisor->cpu_cores_used);
        $this->assertNull($address->fresh()->server_id, 'Adres musi wrócić do puli');
    }

    public function test_powtorzony_callback_nie_zmienia_wyniku_dwa_razy(): void
    {
        $payload = [
            'job_id' => 'agent-job-1',
            'status' => 'done',
            'result' => ['uuid' => 'abc', 'state' => 'running'],
        ];

        $this->send($payload)->assertOk();
        $this->server->markState(ServerState::Stopped); // klient w międzyczasie zatrzymał maszynę

        // Agent ponawia raport (np. nie dostał naszej odpowiedzi). Zadanie jest
        // już zamknięte, więc stan maszyny nie może zostać cofnięty.
        $this->send($payload)->assertOk();

        $this->assertSame(ServerState::Stopped, $this->server->fresh()->state);
    }

    public function test_wynik_usuniecia_zwalnia_maszyne_i_adresy(): void
    {
        $this->server->forceFill(['state' => ServerState::Deleting])->save();
        $deleteJob = ServerJob::create([
            'server_id' => $this->server->id,
            'hypervisor_id' => $this->hypervisor->id,
            'action' => 'delete',
            'status' => ServerJob::STATUS_RUNNING,
            'agent_job_id' => 'agent-job-delete',
        ]);

        $this->send([
            'job_id' => 'agent-job-delete',
            'status' => 'done',
            'result' => ['deleted' => true],
        ])->assertOk();

        $this->assertSoftDeleted('servers', ['id' => $this->server->id]);
        $this->assertSame(ServerJob::STATUS_DONE, $deleteJob->fresh()->status);
    }
}
