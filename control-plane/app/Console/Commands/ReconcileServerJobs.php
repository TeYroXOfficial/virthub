<?php

namespace App\Console\Commands;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Provisioning\AgentResultApplier;
use App\Models\ServerJob;
use Illuminate\Console\Command;

/**
 * Uzgadnianie stanu zadań z hypervisorami.
 *
 * Callback od agenta jest szybszą, ale nie jedyną drogą dostarczenia wyniku:
 * pakiet może zginąć, panel może być akurat restartowany. To polecenie dopytuje
 * o zadania, które od dłuższej chwili wiszą jako „running", żeby maszyna nie
 * została na zawsze w stanie przejściowym.
 */
class ReconcileServerJobs extends Command
{
    protected $signature = 'virthub:reconcile-jobs {--stale=45 : Po ilu sekundach dopytać agenta}';

    protected $description = 'Dopytuje agentów o wynik niezakończonych zadań';

    public function handle(AgentResultApplier $applier): int
    {
        $staleSeconds = (int) $this->option('stale');

        $jobs = ServerJob::query()
            ->whereIn('status', [ServerJob::STATUS_QUEUED, ServerJob::STATUS_RUNNING])
            ->whereNotNull('agent_job_id')
            ->where('updated_at', '<=', now()->subSeconds($staleSeconds))
            ->with('server.hypervisor')
            ->limit(200)
            ->get();

        if ($jobs->isEmpty()) {
            return self::SUCCESS;
        }

        $applied = 0;

        foreach ($jobs as $job) {
            $hypervisor = $job->server?->hypervisor;

            if ($hypervisor === null) {
                $job->markFailed('Hypervisor maszyny został usunięty z systemu.');

                continue;
            }

            try {
                $state = (new AgentClient($hypervisor))->job($job->agent_job_id);
            } catch (AgentException $e) {
                $this->warn("Zadanie {$job->id}: {$e->getMessage()}");

                continue;
            }

            if (in_array($state['status'] ?? '', ['done', 'failed'], true)) {
                $applier->apply($job, $state);
                $applied++;
            } else {
                // Dotknięcie znacznika czasu odsuwa kolejne dopytanie — inaczej
                // długi provisioning odpytywalibyśmy w każdej minucie od nowa.
                $job->touch();
            }
        }

        $this->info("Uzgodniono {$applied} z {$jobs->count()} zadań.");

        return self::SUCCESS;
    }
}
