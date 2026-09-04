<?php

namespace App\Jobs;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Agent\ServerPayload;
use App\Models\ServerJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Wysyła do agenta polecenie dla istniejącej maszyny i zapisuje identyfikator
 * zadania po jego stronie. Wynik dociera osobno — callbackiem albo przez
 * uzgadnianie stanu.
 */
class RunServerActionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $serverJobId) {}

    public function handle(): void
    {
        $job = ServerJob::with(['server.hypervisor', 'server.firewallRules', 'server.ipAddresses.pool'])
            ->find($this->serverJobId);

        if ($job === null || $job->isFinished()) {
            return;
        }

        $server = $job->server;
        $hypervisor = $server->hypervisor;

        if ($hypervisor === null || $server->agent_uuid === null) {
            // Maszyna nigdy nie powstała na hypervisorze (nieudany provisioning).
            // Usunięcie takiej pozycji ma się udać mimo to — klient nie może
            // zostać z rekordem, którego nie da się skasować.
            if ($job->action === 'delete') {
                app(\App\Domain\Provisioning\ServerCleanup::class)->finalise($server);
                $job->markDone(['note' => 'Maszyna nie istniała na hypervisorze.']);

                return;
            }

            $job->markFailed('Maszyna nie jest przypisana do działającego hypervisora.');

            return;
        }

        $job->forceFill(['status' => ServerJob::STATUS_RUNNING])->save();
        $client = new AgentClient($hypervisor);
        $payload = $job->payload ?? [];

        try {
            $agentJobId = match ($job->action) {
                'power' => $client->power($server->agent_uuid, $payload['action']),
                'rebuild' => $client->rebuild($server->agent_uuid, [
                    'template' => $payload['template'],
                    'hostname' => $payload['hostname'] ?? $server->hostname,
                    'ssh_keys' => $payload['ssh_keys'] ?? [],
                    'root_password' => $payload['root_password'] ?? null,
                ]),
                'resize' => $client->resize($server->agent_uuid, [
                    'vcpu' => $payload['vcpu'],
                    'ram_mb' => $payload['ram_mb'],
                    'disk_gb' => $payload['disk_gb'],
                ]),
                'delete' => $client->delete($server->agent_uuid),
                'snapshot' => $client->snapshot($server->agent_uuid, $payload['name']),
                'restore' => $client->restore($server->agent_uuid, $payload['name']),
                'network' => $client->configureNetwork(
                    $server->agent_uuid,
                    ServerPayload::forNetwork($server),
                ),
                default => throw new \LogicException("Nieznana akcja: {$job->action}"),
            };
        } catch (AgentException $e) {
            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 30);

                return;
            }

            Log::error('Operacja na maszynie nieudana', [
                'server_id' => $server->id,
                'action' => $job->action,
                'error' => $e->getMessage(),
            ]);

            $job->markFailed($e->getMessage());
            app(\App\Domain\Provisioning\AgentResultApplier::class)->rollbackState($job);

            return;
        }

        $job->forceFill(['agent_job_id' => $agentJobId])->save();
    }
}
