<?php

namespace App\Jobs;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Provisioning\Migrations;
use App\Models\Hypervisor;
use App\Models\ServerJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Prowadzi migrację przez kolejne etapy, odpytując agentów obu węzłów.
 *
 * cold: start → exporting (źródło) → importing (cel) → koniec (sprzątanie źródła)
 * live: start → preparing (cel) → migrating (źródło) → koniec
 */
class MigrateServerJob implements ShouldQueue
{
    use Queueable;

    private const POLL_SECONDS = 5;

    public function __construct(public readonly int $serverJobId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(Migrations $migrations): void
    {
        $job = ServerJob::with('server')->find($this->serverJobId);
        if ($job === null || $job->isFinished() || $job->server === null) {
            return;
        }
        $p = $job->payload;
        $source = Hypervisor::find($p['source_id']);
        $destination = Hypervisor::find($p['destination_id']);
        if ($source === null || $destination === null) {
            $migrations->fail($job, __('Węzeł migracji został usunięty.'));

            return;
        }
        $server = $job->server;

        try {
            match ($p['phase']) {
                'start' => $p['mode'] === 'live'
                    ? $this->next($job, 'preparing', (new AgentClient($destination))->prepareMigration($migrations->payloadFor($server, $destination)))
                    : $this->next($job, 'exporting', (new AgentClient($source))->exportVm($server->agent_uuid)),
                'exporting' => $this->poll($job, $source, function (array $result) use ($job, $source, $destination, $server, $migrations) {
                    $this->next($job, 'importing', (new AgentClient($destination))->importVm($migrations->payloadFor($server, $destination) + ['source' => [
                        'url' => (new AgentClient($source))->transferUrl($result['token']),
                        'sha256' => $result['sha256'],
                        'size_bytes' => $result['size_bytes'] ?? null,
                        'start' => (bool) ($job->payload['was_running'] ?? false),
                    ]]), ['token' => $result['token']]);
                }),
                'importing' => $this->poll($job, $destination, function (array $result) use ($job, $source, $server, $migrations) {
                    $this->cleanupSource($source, $server->agent_uuid, $job->payload['token'] ?? null);
                    $migrations->finish($job, $result);
                }),
                'preparing' => $this->poll($job, $destination, function () use ($job, $source, $destination, $server) {
                    $host = $destination->public_address ?: $destination->hostname ?: parse_url((string) $destination->agent_url, PHP_URL_HOST);
                    $this->next($job, 'migrating', (new AgentClient($source))->migrateLive($server->agent_uuid, (string) $host));
                }),
                'migrating' => $this->poll($job, $source, fn (array $result) => $migrations->finish($job, $result)),
                default => $migrations->fail($job, __('Nieznany etap migracji.')),
            };
        } catch (AgentException $e) {
            if ($e->isRetryable() && $this->attempts() < 20) {
                $this->release(30);

                return;
            }
            $this->abort($job, $source, $e->getMessage(), $migrations);
        } catch (MigrationStepFailed $e) {
            $this->abort($job, $source, $e->getMessage(), $migrations);
        }
    }

    private function next(ServerJob $job, string $phase, string $agentJobId, array $extra = []): void
    {
        $job->forceFill(['status' => ServerJob::STATUS_RUNNING, 'payload' => array_merge($job->payload, $extra, ['phase' => $phase, 'sub_job' => $agentJobId])])->save();
        $this->release(self::POLL_SECONDS);
    }

    /** Czeka na zadanie agenta bieżącego etapu; po sukcesie woła $then z wynikiem. */
    private function poll(ServerJob $job, Hypervisor $node, callable $then): void
    {
        $state = (new AgentClient($node))->job((string) $job->payload['sub_job']);
        match ($state['status'] ?? null) {
            'done' => $then((array) ($state['result'] ?? [])),
            'failed' => throw new MigrationStepFailed((string) ($state['error'] ?? __('Węzeł nie podał przyczyny.'))),
            default => $this->release(self::POLL_SECONDS),
        };
    }

    /** Po nieudanym imporcie maszyna wraca do życia na źródle. */
    private function abort(ServerJob $job, Hypervisor $source, string $error, Migrations $migrations): void
    {
        $p = $job->payload;
        $server = $job->server;
        if ($p['mode'] === 'cold' && in_array($p['phase'], ['exporting', 'importing'], true)) {
            try {
                if (! empty($p['token'])) {
                    (new AgentClient($source))->revokeTransfer($p['token']);
                }
                if ($p['was_running'] ?? false) {
                    (new AgentClient($source))->power($server->agent_uuid, 'start');
                    $job->forceFill(['payload' => $p + ['restarted' => true]])->save();
                }
            } catch (AgentException) {
                // Źródło niedostępne — administrator uruchomi maszynę ręcznie.
            }
        }
        $migrations->fail($job->fresh(), $error);
    }

    private function cleanupSource(Hypervisor $source, string $uuid, ?string $token): void
    {
        try {
            $client = new AgentClient($source);
            if ($token) {
                $client->revokeTransfer($token);
            }
            $client->delete($uuid);
        } catch (AgentException) {
            // Pozostałości na źródle (wyłączona domena, dysk) usunie administrator — maszyna działa już na celu.
        }
    }
}
