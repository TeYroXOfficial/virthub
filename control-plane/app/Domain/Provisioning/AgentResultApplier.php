<?php

namespace App\Domain\Provisioning;

use App\Enums\ServerState;
use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Przenoszenie wyniku zadania z hypervisora na stan w bazie.
 *
 * Jedno miejsce dla obu dróg dostarczenia wyniku: callbacku od agenta i
 * cyklicznego uzgadniania. Gdyby każda z nich miała własną logikę, prędzej czy
 * później rozjechałyby się w interpretacji tego samego wyniku.
 */
class AgentResultApplier
{
    public function __construct(private readonly ServerCleanup $cleanup) {}

    /**
     * @param  array{status: string, result?: array|null, error?: string|null}  $agentState
     */
    public function apply(ServerJob $job, array $agentState): void
    {
        if ($job->isFinished()) {
            return; // wynik już zastosowany — callback i uzgadnianie mogą się minąć
        }

        $status = $agentState['status'] ?? 'failed';

        if ($status === 'done') {
            $this->applySuccess($job, $agentState['result'] ?? []);

            return;
        }

        if ($status === 'failed') {
            $this->applyFailure($job, $agentState['error'] ?? 'Hypervisor nie podał przyczyny.');
        }
    }

    private function applySuccess(ServerJob $job, ?array $result): void
    {
        $result ??= [];
        $server = $job->server;

        match ($job->action) {
            'create' => $this->finishCreate($server, $result),
            'power' => $this->applyPowerState($server, $result),
            'rebuild' => $this->finishRebuild($server),
            'resize' => $this->finishResize($server, $job),
            'delete' => $this->cleanup->finalise($server),
            'snapshot' => $this->finishSnapshot($job, $result),
            'restore' => $server->markState(ServerState::Running),
            'network' => null,
            'iso' => $this->finishIso($server, $job, $result),
            'password' => $this->finishPassword($server, $job),
            default => Log::warning('Nieznana akcja w wyniku zadania', ['action' => $job->action]),
        };

        $job->markDone($result);
    }

    private function applyFailure(ServerJob $job, string $error): void
    {
        $server = $job->server;

        match ($job->action) {
            'create' => $this->failCreate($server, $error),
            'snapshot' => $this->failSnapshot($job),
            // Hasło, którego agent nie ustawił, nie może zostać w bazie
            // — pokazane klientowi wyglądałoby na działające.
            'rebuild' => $server->forceFill(['root_password' => null])->save(),
            'password' => $job->forceFill(['payload' => []])->save(),
            default => null,
        };

        $this->rollbackState($job, $error);
        $job->markFailed($error);
    }

    /**
     * Przywraca maszynie stan spoczynkowy po nieudanej operacji — bez tego
     * zostałaby w stanie przejściowym na zawsze i klient nie mógłby nic zrobić.
     */
    public function rollbackState(ServerJob $job, ?string $error = null): void
    {
        $server = $job->server;

        if ($job->action === 'create' || ! $server->state->isTransitioning()) {
            return;
        }

        $server->markState(
            ServerState::Stopped,
            $error ? "Ostatnia operacja nie powiodła się: {$error}" : null,
        );
    }

    // --- poszczególne akcje -------------------------------------------------

    private function finishCreate(Server $server, array $result): void
    {
        $server->forceFill([
            'agent_uuid' => $result['uuid'] ?? null,
            'mac_address' => $result['mac'] ?? null,
            'vnc_port' => $result['vnc_port'] ?? null,
            'vnc_password' => $result['vnc_password'] ?? null,
            'state' => ServerState::fromAgentState($result['state'] ?? 'running'),
            'state_message' => null,
            'build_progress' => 100,
            'last_synced_at' => now(),
        ])->save();

        // Agent tworzy maszynę z samym anty-spoofingiem. Zapora ustawiona
        // w trakcie tworzenia trafia na węzeł osobnym zleceniem.
        if ($server->firewall_enabled || $server->firewallRules()->exists()) {
            app(ServerProvisioner::class)->syncNetwork($server);
        }
    }

    /** Hasło trafia do maszyny (pokazane raz na stronie), a znika z zadania. */
    private function finishPassword(Server $server, ServerJob $job): void
    {
        if (! empty($job->payload['password'])) {
            $server->forceFill(['root_password' => Crypt::decryptString($job->payload['password'])])->save();
            $job->forceFill(['payload' => []])->save();
        }
    }

    private function finishIso(Server $server, ServerJob $job, array $result): void
    {
        $server->forceFill([
            'iso_image_id' => $job->payload['iso_image_id'] ?? null,
            'boot_from_iso' => (bool) ($result['boot'] ?? false),
        ])->save();

        if (! empty($result['state'])) {
            $server->markState(ServerState::fromAgentState($result['state']));
        }
    }

    private function failCreate(Server $server, string $error): void
    {
        app(IpAllocator::class)->releaseAll($server);
        app(HypervisorSelector::class)->release($server);

        $server->markState(ServerState::Error, 'Nie udało się utworzyć maszyny: '.$error);
    }

    private function applyPowerState(Server $server, array $result): void
    {
        // Zawieszona maszyna zostaje zawieszona, choćby libvirt raportował
        // „stopped" — to stan administracyjny, nie techniczny.
        if ($server->isSuspended()) {
            $server->markState(ServerState::Suspended, $server->suspension_reason);

            return;
        }

        $server->forceFill([
            'state' => ServerState::fromAgentState($result['state'] ?? 'stopped'),
            'state_message' => null,
            'last_synced_at' => now(),
        ])->save();
    }

    private function finishRebuild(Server $server): void
    {
        $server->forceFill([
            'state' => ServerState::Running,
            'state_message' => null,
            'build_progress' => 100,
            'last_synced_at' => now(),
        ])->save();
    }

    private function finishResize(Server $server, ServerJob $job): void
    {
        $payload = $job->payload ?? [];

        $server->forceFill([
            'vcpu' => $payload['vcpu'] ?? $server->vcpu,
            'ram_mb' => $payload['ram_mb'] ?? $server->ram_mb,
            'disk_gb' => $payload['disk_gb'] ?? $server->disk_gb,
            'vps_package_id' => $payload['package_id'] ?? $server->vps_package_id,
            'state' => ServerState::Stopped,
            'state_message' => null,
        ])->save();
    }

    private function finishSnapshot(ServerJob $job, array $result): void
    {
        $backup = Backup::find($job->payload['backup_id'] ?? null);

        $backup?->forceFill([
            'status' => Backup::STATUS_READY,
            'storage_path' => $result['path'] ?? null,
        ])->save();
    }

    private function failSnapshot(ServerJob $job): void
    {
        $backup = Backup::find($job->payload['backup_id'] ?? null);

        $backup?->forceFill(['status' => Backup::STATUS_FAILED])->save();
    }
}
