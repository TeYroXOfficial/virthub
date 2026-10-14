<?php

namespace App\Jobs;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Agent\ServerPayload;
use App\Domain\Network\PrivateNetworks;
use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\ServerCleanup;
use App\Models\Backup;
use App\Models\Server;
use App\Models\ServerJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
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
                app(ServerCleanup::class)->finalise($server);
                $job->markDone(['note' => __('Maszyna nie istniała na hypervisorze.')]);

                return;
            }

            $job->markFailed(__('Maszyna nie jest przypisana do działającego hypervisora.'));

            return;
        }

        $job->forceFill(['status' => ServerJob::STATUS_RUNNING])->save();
        $client = new AgentClient($hypervisor);
        $payload = $job->payload ?? [];

        try {
            $agentJobId = match ($job->action) {
                'power' => $client->power($server->agent_uuid, $payload['action']),
                'elevate' => $client->elevate($server->agent_uuid, $payload['action']),
                'backup', 'backup_restore' => $this->backupCall($client, $server, $job->action, $payload),
                'recover' => $this->recoverCall($client, $server, $payload),
                'hostdevs' => $client->setHostdevs($server->agent_uuid,
                    $server->pciAssets()->get()->map->toAgentPayload()->values()->all(), (bool) ($payload['restart'] ?? false)),
                'private_nics' => $client->setPrivateNics($server->agent_uuid, PrivateNetworks::payload($server)),
                'rebuild' => $client->rebuild($server->agent_uuid, [
                    'template' => $payload['template'],
                    'hostname' => $payload['hostname'] ?? $server->hostname,
                    'ssh_keys' => $payload['ssh_keys'] ?? [],
                    'root_password' => $payload['root_password'] ?? null,
                    // Adresacja dla cloud-init nowego systemu — bez niej maszyna
                    // KVM po reinstalacji wstawała bez sieci.
                    'interfaces' => ServerPayload::interfaces($server),
                    'nameservers' => ServerPayload::nameservers($server),
                    'os_type' => ServerPayload::osType($server->template),
                    'mac' => ServerPayload::mac($server),
                ] + ServerPayload::scripts($server) + ServerPayload::privateNics($server)),
                'password' => $client->resetPassword(
                    $server->agent_uuid,
                    Crypt::decryptString($payload['password']),
                    $server->osFamily() === 'windows' ? 'Administrator' : 'root',
                ),
                'iso' => $client->mountIso(
                    $server->agent_uuid,
                    $payload['filename'] ?? null,
                    (bool) ($payload['boot'] ?? false),
                    (bool) ($payload['restart'] ?? false),
                ),
                'resize' => $client->resize($server->agent_uuid, [
                    'vcpu' => $payload['vcpu'],
                    'ram_mb' => $payload['ram_mb'],
                    'disk_gb' => $payload['disk_gb'],
                    'cpu_limit_percent' => $payload['cpu_limit_percent'] ?? null,
                ]),
                'cpu_limit' => $client->cpuLimit($server->agent_uuid, $payload['cpu_limit_percent'] ?? null),
                'mac' => $client->setMac($server->agent_uuid, ServerPayload::mac($server)),
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
            app(AgentResultApplier::class)->rollbackState($job);

            return;
        }

        $job->forceFill(['agent_job_id' => $agentJobId])->save();
    }

    private function recoverCall(AgentClient $client, Server $server, array $payload): string
    {
        $backup = Backup::with('target')->findOrFail($payload['backup_id']);
        if ($backup->target === null) {
            throw new AgentException(__('Cel kopii został usunięty.'));
        }

        return $client->recoverVm(ServerPayload::forCreate($server) + ['backup' => array_filter([
            'key' => (string) $backup->remote_key, 'target' => $backup->target->toAgentPayload(), 'sha256' => $backup->sha256,
            'chain' => $backup->chainPayload(),
        ])]);
    }

    private function backupCall(AgentClient $client, Server $server, string $action, array $payload): string
    {
        $backup = Backup::with('target')->findOrFail($payload['backup_id']);
        if ($backup->target === null) {
            throw new AgentException(__('Cel kopii został usunięty.'));
        }

        return $action === 'backup'
            ? $client->backup($server->agent_uuid, (string) $backup->remote_key, $backup->target->toAgentPayload(), (bool) ($payload['compress'] ?? true),
                $backup->checkpoint, $backup->parent?->checkpoint)
            : $client->restoreBackup($server->agent_uuid, (string) $backup->remote_key, $backup->target->toAgentPayload(), $backup->sha256, $backup->chainPayload());
    }
}
