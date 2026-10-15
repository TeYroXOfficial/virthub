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
 * wznowienie: resume-import → importing (eksport ze źródła z poprzedniej próby)
 *
 * Każdy etap trafia do payload.steps, bieżący postęp agenta do payload.progress.
 * Anulowanie (payload.cancel) kończy migrację na najbliższej bezpiecznej granicy:
 * przed startem od razu, migrację na żywo przerywa w libvirt, eksport i import
 * dochodzą do końca, po czym cel jest sprzątany, a źródło wraca do pracy.
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
        $cancel = (bool) ($p['cancel'] ?? false);

        try {
            if ($cancel && in_array($p['phase'], ['start', 'resume-import'], true)) {
                $this->abort($job, $source, $destination, __('Migracja anulowana.'), $migrations, cancelled: true);

                return;
            }
            if ($cancel && $p['phase'] === 'migrating' && empty($p['abort_sent'])) {
                (new AgentClient($source))->abortMigration($server->agent_uuid);
                $this->save($job, ['abort_sent' => true]);
            }

            match ($p['phase']) {
                'start' => $p['mode'] === 'live'
                    ? $this->next($job, 'preparing', (new AgentClient($destination))->prepareMigration($migrations->payloadFor($server, $destination)))
                    : $this->next($job, 'exporting', (new AgentClient($source))->exportVm($server->agent_uuid)),
                'exporting' => $this->poll($job, $source, function (array $result) use ($job, $source, $destination, $migrations) {
                    $export = ['token' => $result['token'], 'sha256' => $result['sha256'], 'size_bytes' => $result['size_bytes'] ?? null];
                    $this->save($job, ['token' => $result['token'], 'export' => $export]);
                    if ($job->payload['cancel'] ?? false) {
                        $this->abort($job, $source, $destination, __('Migracja anulowana.'), $migrations, cancelled: true);

                        return;
                    }
                    $this->startImport($job, $source, $destination, $migrations);
                }),
                'resume-import' => $this->resumeImport($job, $source, $destination, $migrations),
                'importing' => $this->poll($job, $destination, function (array $result) use ($job, $source, $destination, $server, $migrations) {
                    if ($job->payload['cancel'] ?? false) {
                        $this->abort($job, $source, $destination, __('Migracja anulowana.'), $migrations, cancelled: true);

                        return;
                    }
                    $this->finishStep($job);
                    $this->cleanupSource($source, $server->agent_uuid, $job->payload['token'] ?? null);
                    $migrations->finish($job->fresh(), $result);
                }),
                'preparing' => $this->poll($job, $destination, function () use ($job, $source, $destination, $server, $migrations) {
                    if ($job->payload['cancel'] ?? false) {
                        $this->abort($job, $source, $destination, __('Migracja anulowana.'), $migrations, cancelled: true);

                        return;
                    }
                    $this->next($job, 'migrating', (new AgentClient($source))->migrateLive($server->agent_uuid, self::migrationHost($destination)));
                }),
                'migrating' => $this->poll($job, $source, function (array $result) use ($job, $migrations) {
                    $this->finishStep($job);
                    $migrations->finish($job->fresh(), $result);
                }),
                default => $migrations->fail($job, __('Nieznany etap migracji.')),
            };
        } catch (AgentException $e) {
            if ($e->isRetryable() && $this->attempts() < 20) {
                $this->release(30);

                return;
            }
            $this->abort($job, $source, $destination, $e->getMessage(), $migrations);
        } catch (MigrationStepFailed $e) {
            $cancelled = (bool) ($job->fresh()->payload['cancel'] ?? false);
            $this->abort($job, $source, $destination, $cancelled ? __('Migracja anulowana.') : $e->getMessage(), $migrations, cancelled: $cancelled);
        }
    }

    /** Adres celu dla libvirt: adres migracji, publiczny, nazwa albo host agenta. */
    public static function migrationHost(Hypervisor $node): string
    {
        return (string) ($node->migration_address ?: $node->public_address ?: $node->hostname ?: parse_url((string) $node->agent_url, PHP_URL_HOST));
    }

    private function startImport(ServerJob $job, Hypervisor $source, Hypervisor $destination, Migrations $migrations): void
    {
        $export = $job->payload['export'];
        $this->next($job, 'importing', (new AgentClient($destination))->importVm($migrations->payloadFor($job->server, $destination) + ['source' => [
            'url' => (new AgentClient($source))->transferUrl($export['token']),
            'sha256' => $export['sha256'],
            'size_bytes' => $export['size_bytes'] ?? null,
            'start' => (bool) ($job->payload['was_running'] ?? false),
        ]]));
    }

    /** Wznowienie: najpierw sprzątanie resztek poprzedniej próby na celu (ta sama kolejka agenta), potem import. */
    private function resumeImport(ServerJob $job, Hypervisor $source, Hypervisor $destination, Migrations $migrations): void
    {
        (new AgentClient($destination))->cleanupIncoming($job->server_id);
        $this->startImport($job, $source, $destination, $migrations);
    }

    private function save(ServerJob $job, array $changes): void
    {
        $job->forceFill(['payload' => array_merge($job->payload, $changes)])->save();
    }

    private function next(ServerJob $job, string $phase, string $agentJobId): void
    {
        $steps = $this->closeSteps($job->payload['steps'] ?? []);
        $steps[] = ['phase' => $phase, 'started_at' => now()->toIso8601String(), 'finished_at' => null];
        $job->forceFill(['status' => ServerJob::STATUS_RUNNING, 'payload' => array_merge($job->payload, [
            'phase' => $phase, 'sub_job' => $agentJobId, 'steps' => $steps, 'progress' => null,
        ])])->save();
        $this->release(self::POLL_SECONDS);
    }

    private function finishStep(ServerJob $job): void
    {
        $this->save($job, ['steps' => $this->closeSteps($job->payload['steps'] ?? []), 'progress' => ['percent' => 100] + (array) ($job->payload['progress'] ?? [])]);
    }

    private function closeSteps(array $steps): array
    {
        foreach ($steps as $i => $step) {
            $steps[$i]['finished_at'] ??= now()->toIso8601String();
        }

        return $steps;
    }

    /** Czeka na zadanie agenta bieżącego etapu; po sukcesie woła $then z wynikiem. */
    private function poll(ServerJob $job, Hypervisor $node, callable $then): void
    {
        $state = (new AgentClient($node))->job((string) $job->payload['sub_job']);
        match ($state['status'] ?? null) {
            'done' => $then((array) ($state['result'] ?? [])),
            'failed' => throw new MigrationStepFailed((string) ($state['error'] ?? __('Węzeł nie podał przyczyny.'))),
            default => $this->waiting($job, $state),
        };
    }

    private function waiting(ServerJob $job, array $state): void
    {
        $progress = array_filter([
            'stage' => $state['stage'] ?? null,
            'percent' => isset($state['progress']) ? (int) $state['progress'] : null,
            'detail' => $state['detail'] ?? null,
        ], fn ($v) => $v !== null);
        if ($progress !== ($job->payload['progress'] ?? null)) {
            $this->save($job, ['progress' => $progress]);
        }
        $this->release(self::POLL_SECONDS);
    }

    /**
     * Koniec bez sukcesu: cel jest sprzątany, eksport unieważniany, a maszyna
     * wraca do pracy na źródle. Wyjątek: zatrzymana maszyna po nieudanym
     * imporcie — eksport zostaje, żeby dało się wznowić od pobierania.
     */
    private function abort(ServerJob $job, Hypervisor $source, Hypervisor $destination, string $error, Migrations $migrations, bool $cancelled = false): void
    {
        $job = $job->fresh();
        $p = $job->payload;
        $server = $job->server;
        $phase = $p['phase'];
        $resumable = ! $cancelled && $p['mode'] === 'cold' && in_array($phase, ['importing', 'resume-import'], true)
            && ! ($p['was_running'] ?? false) && ! empty($p['export']['token']);

        if (in_array($phase, ['importing', 'resume-import', 'preparing', 'migrating'], true)) {
            try {
                (new AgentClient($destination))->cleanupIncoming($server->id);
            } catch (AgentException) {
                // Cel niedostępny — „Cofnij” na stronie maszyny spróbuje ponownie.
            }
        }
        if ($p['mode'] === 'cold' && in_array($phase, ['exporting', 'importing', 'resume-import'], true)) {
            try {
                if (! empty($p['token']) && ! $resumable) {
                    (new AgentClient($source))->revokeTransfer($p['token']);
                }
                if ($p['was_running'] ?? false) {
                    (new AgentClient($source))->power($server->agent_uuid, 'start');
                    $p['restarted'] = true;
                }
            } catch (AgentException) {
                // Źródło niedostępne — administrator uruchomi maszynę ręcznie.
            }
        }
        $steps = $p['steps'] ?? [];
        if ($steps !== [] && end($steps)['finished_at'] === null) {
            $steps[array_key_last($steps)]['failed'] = true;
        }
        $job->forceFill(['payload' => array_merge($p, ['steps' => $this->closeSteps($steps), 'resumable' => $resumable ? 'importing' : null, 'cancelled' => $cancelled])])->save();
        $migrations->fail($job->fresh(), $error, $cancelled);
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
