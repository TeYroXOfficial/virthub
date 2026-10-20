<?php

namespace App\Jobs;

use App\Domain\Security\VulnerabilityFeed;
use App\Models\Hypervisor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sprawdzenie pakietów węzła w bazie luk — po „Sprawdź teraz”, bez trzymania żądania.
 *
 * Pierwsze sprawdzenie pobiera kilka tysięcy opisów (kilka minut), a kolejka
 * uznaje zadanie dłuższe niż retry_after (90 s) za zawieszone. Dlatego każde
 * uruchomienie pracuje najwyżej BUDGET sekund i w razie potrzeby zleca
 * ciąg dalszy — lista CVE jest zapisana od razu, opisy dochodzą w kolejnych
 * porcjach.
 */
class ScanVulnerabilitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const BUDGET = 50;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(public readonly ?int $hypervisorId = null, public readonly bool $requery = true) {}

    public function handle(VulnerabilityFeed $feed): void
    {
        $nodes = $this->hypervisorId === null ? null : Hypervisor::query()->whereKey($this->hypervisorId)->get();
        $result = $feed->scan($nodes, microtime(true) + self::BUDGET, $this->requery);
        if (! $result['complete']) {
            self::dispatch($this->hypervisorId, false);
        }
    }
}
