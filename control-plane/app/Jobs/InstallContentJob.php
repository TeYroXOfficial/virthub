<?php

namespace App\Jobs;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Apps\Content\ContentException;
use App\Domain\Apps\Content\ContentManager;
use App\Models\AppJob;
use App\Models\AppServer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Modpack, loader, plugin albo mod: rozwiązanie paczki do kroków (dla
 * modpacka pobiera jego archiwum — dlatego w kolejce) i zlecenie agentowi.
 */
class InstallContentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public readonly int $appJobId) {}

    public function handle(ContentManager $content): void
    {
        $job = AppJob::with('server.hypervisor', 'server.egg', 'server.allocations')->find($this->appJobId);
        if ($job === null || $job->isFinished() || $job->server === null) {
            return;
        }
        $app = $job->server;
        if ($app->hypervisor === null) {
            $content->applyResult($job, false, __('Aplikacja nie jest przypisana do węzła.'));

            return;
        }

        $job->forceFill(['status' => AppJob::STATUS_RUNNING])->save();

        try {
            if ($job->action === 'addon') {
                $request = $job->payload['request'];
            } else {
                $built = $content->buildServer($app, $job);
                $request = $built['request'];
                $job->forceFill(['payload' => $job->payload + ['minecraft' => $built['minecraft']]])->save();
            }
            $agentJobId = (new AgentClient($app->hypervisor))->appContent($app->uuid, $request);
        } catch (ContentException $e) {
            $content->applyResult($job, false, $e->getMessage());

            return;
        } catch (AgentException $e) {
            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                $job->forceFill(['status' => AppJob::STATUS_QUEUED])->save();
                $this->release($this->backoff[$this->attempts() - 1] ?? 30);

                return;
            }
            $content->applyResult($job, false, $e->getMessage());

            return;
        } catch (\Throwable $e) {
            Log::error('Instalacja treści aplikacji nie powiodła się', ['app' => $app->uuid, 'error' => $e->getMessage()]);
            $content->applyResult($job, false, __('Nie udało się przygotować instalacji: :error', ['error' => mb_substr($e->getMessage(), 0, 300)]));

            return;
        }

        $job->forceFill(['agent_job_id' => $agentJobId])->save();
        if ($job->action !== 'addon') {
            // Konsola pokazuje postęp instalacji na żywo, jak przy instalacji aplikacji.
            $app->forceFill(['status' => AppServer::STATUS_INSTALLING, 'status_message' => $request['label']])->save();
        }
    }
}
