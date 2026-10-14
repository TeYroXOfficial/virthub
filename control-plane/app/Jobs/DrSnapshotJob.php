<?php

namespace App\Jobs;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Odbiór wyniku zrzutu konfiguracji węzła (DR) — odpytuje agenta do zakończenia. */
class DrSnapshotJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $hypervisorId, public readonly string $key) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(): void
    {
        $node = Hypervisor::find($this->hypervisorId);
        if ($node === null || $node->dr_snapshot_job_id === null) {
            return;
        }
        try {
            $state = (new AgentClient($node))->job($node->dr_snapshot_job_id);
        } catch (AgentException $e) {
            if ($e->isRetryable()) {
                $this->release(30);

                return;
            }
            $state = ['status' => 'failed', 'error' => $e->getMessage()];
        }

        match ($state['status'] ?? null) {
            'done' => $this->finish($node, null),
            'failed' => $this->finish($node, (string) ($state['error'] ?? __('Węzeł nie podał przyczyny.'))),
            default => $this->release(10),
        };
    }

    private function finish(Hypervisor $node, ?string $error): void
    {
        $node->forceFill(array_filter([
            'dr_snapshot_job_id' => null,
            'dr_last_error' => $error === null ? null : mb_substr($error, 0, 500),
            'dr_last_snapshot_at' => $error === null ? now() : null,
            'dr_last_snapshot_key' => $error === null ? $this->key : null,
        ], fn ($v, $k) => $v !== null || in_array($k, ['dr_snapshot_job_id', 'dr_last_error'], true), ARRAY_FILTER_USE_BOTH))->save();
        AuditLog::record($error === null ? 'hypervisor.dr_snapshot' : 'hypervisor.dr_snapshot_failed', $node, ['key' => $this->key, 'error' => $error]);
    }
}
