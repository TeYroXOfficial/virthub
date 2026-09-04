<?php

namespace App\Jobs;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Agent\ServerPayload;
use App\Domain\Provisioning\HypervisorSelector;
use App\Domain\Provisioning\IpAllocator;
use App\Enums\ServerState;
use App\Models\ServerJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Zleca agentowi utworzenie maszyny.
 *
 * Zadanie kończy się w chwili przyjęcia zlecenia przez agenta — właściwy
 * provisioning trwa dalej po jego stronie, a wynik wraca callbackiem albo
 * przez ReconcilePendingJobs. Trzymanie tu otwartego połączenia przez minutę
 * blokowałoby workera i tak nie dawało gwarancji dostarczenia wyniku.
 */
class ProvisionServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /** @param  list<string>  $sshKeys */
    public function __construct(
        public readonly int $serverJobId,
        public readonly array $sshKeys = [],
    ) {}

    public function handle(HypervisorSelector $selector, IpAllocator $ips): void
    {
        $job = ServerJob::with(['server.hypervisor', 'server.template', 'server.ipAddresses.pool'])
            ->find($this->serverJobId);

        if ($job === null || $job->isFinished()) {
            return;
        }

        $server = $job->server;
        $hypervisor = $server->hypervisor;

        if ($hypervisor === null) {
            $this->fail($job, 'Maszyna nie ma przypisanego hypervisora.', $selector, $ips);

            return;
        }

        $job->forceFill(['status' => ServerJob::STATUS_RUNNING])->save();

        try {
            $agentJobId = (new AgentClient($hypervisor))
                ->createVm(ServerPayload::forCreate($server, $this->sshKeys));
        } catch (AgentException $e) {
            if ($e->isRetryable() && $this->attempts() < $this->tries) {
                Log::warning('Provisioning odrzucony, ponawiam', [
                    'server_id' => $server->id,
                    'attempt' => $this->attempts(),
                    'error' => $e->getMessage(),
                ]);

                $this->release($this->backoff[$this->attempts() - 1] ?? 30);

                return;
            }

            $this->fail($job, $e->getMessage(), $selector, $ips);

            return;
        }

        $job->forceFill(['agent_job_id' => $agentJobId])->save();
        $server->forceFill(['build_progress' => 25])->save();
    }

    /** Nieudane zlecenie musi oddać zasoby, inaczej node „traci" pojemność. */
    private function fail(
        ServerJob $job,
        string $message,
        HypervisorSelector $selector,
        IpAllocator $ips,
    ): void {
        $server = $job->server;

        $ips->releaseAll($server);
        $selector->release($server);

        $server->markState(
            ServerState::Error,
            'Nie udało się utworzyć maszyny: '.$message,
        );
        $job->markFailed($message);

        Log::error('Provisioning nieudany', [
            'server_id' => $server->id,
            'server_job_id' => $job->id,
            'error' => $message,
        ]);
    }
}
