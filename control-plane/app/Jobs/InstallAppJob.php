<?php

namespace App\Jobs;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Apps\AppJobApplier;
use App\Domain\Apps\AppPayload;
use App\Models\AppJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Zleca węzłowi instalację (albo reinstalację) aplikacji. Wynik przychodzi callbackiem. */
class InstallAppJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Migracja z Pterodactyla czeka, aż Wings spakuje pliki serwera. */
    public int $timeout = 1800;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $appJobId) {}

    public function handle(AppJobApplier $applier): void
    {
        $job = AppJob::with('server.hypervisor', 'server.egg', 'server.allocations')->find($this->appJobId);
        if ($job === null || $job->isFinished() || $job->server === null) {
            return;
        }

        $app = $job->server;
        if ($app->hypervisor === null) {
            $applier->fail($job, __('Aplikacja nie jest przypisana do węzła.'));

            return;
        }

        $job->forceFill(['status' => AppJob::STATUS_RUNNING])->save();
        $client = new AgentClient($app->hypervisor);
        $spec = AppPayload::spec($app);
        if ($job->action === 'reinstall' && ! empty($job->payload['wipe'])) {
            $spec['reinstall_wipe'] = $job->payload['wipe'];
        }
        if (! empty($job->payload['pterodactyl'])) {
            // Migracja: pliki serwera z Pterodactyla zamiast skryptu instalacji eggu.
            try {
                $spec['install'] = app(\App\Domain\Apps\PterodactylMigrator::class)->installFor($job);
            } catch (\RuntimeException $e) {
                $applier->fail($job, __('Migracja z Pterodactyla: :error', ['error' => $e->getMessage()]));

                return;
            }
        }

        try {
            $agentJobId = $job->action === 'reinstall'
                ? $client->appReinstall($app->uuid, $spec)
                : $client->appInstall($spec);
        } catch (AgentException $e) {
            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 30);

                return;
            }
            $applier->fail($job, $e->getMessage());

            return;
        }

        $job->forceFill(['agent_job_id' => $agentJobId])->save();
    }
}
