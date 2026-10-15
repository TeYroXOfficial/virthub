<?php

namespace App\Jobs;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\ServerJob;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Skrypt administratora po odtworzeniu DR (albo odczyt wyniku skryptu przed).
 *
 * post: czeka, aż zakończą się zadania odtworzenia wszystkich maszyn, zleca
 *       skrypt na zastępczym węźle i zapisuje jego wynik w dzienniku;
 * pre:  skrypt jest już w kolejce agenta (przed odtwarzaniem) — tu tylko wynik.
 */
class DrHookJob implements ShouldQueue
{
    use Queueable;

    private const POLL_SECONDS = 30;

    /** @param  list<int>  $serverIds */
    public function __construct(
        public readonly int $replacementId,
        public readonly string $phase,
        public readonly array $env,
        public readonly array $serverIds = [],
        public ?string $agentJobId = null,
    ) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(): void
    {
        $node = Hypervisor::find($this->replacementId);
        if ($node === null) {
            return;
        }
        $client = new AgentClient($node);
        try {
            if ($this->agentJobId === null) {
                $pending = ServerJob::query()->whereIn('server_id', $this->serverIds)->where('action', 'recover')
                    ->whereNotIn('status', [ServerJob::STATUS_DONE, ServerJob::STATUS_FAILED])->exists();
                if ($pending) {
                    $this->release(self::POLL_SECONDS);

                    return;
                }
                $failed = ServerJob::query()->whereIn('server_id', $this->serverIds)->where('action', 'recover')->where('status', ServerJob::STATUS_FAILED)->count();
                $script = (string) Setting::get('dr.post_script', '');
                if (trim($script) === '') {
                    return;
                }
                $this->agentJobId = $client->runHook('dr-post', $script, $this->env + ['VH_RECOVERED' => (string) (count($this->serverIds) - $failed), 'VH_FAILED' => (string) $failed]);
                $this->release(10);

                return;
            }
            $state = $client->job($this->agentJobId);
        } catch (AgentException $e) {
            if ($e->isRetryable() && $this->attempts() < 30) {
                $this->release(self::POLL_SECONDS);

                return;
            }
            AuditLog::record('hypervisor.dr_hook', $node, ['phase' => $this->phase, 'ok' => false, 'error' => $e->getMessage()]);

            return;
        }
        if (! in_array($state['status'] ?? null, ['done', 'failed'], true)) {
            $this->release(10);

            return;
        }
        AuditLog::record('hypervisor.dr_hook', $node, [
            'phase' => $this->phase,
            'ok' => ($state['status'] ?? null) === 'done',
            'output' => mb_substr((string) ($state['result']['output'] ?? $state['error'] ?? ''), -4000),
        ]);
    }
}
