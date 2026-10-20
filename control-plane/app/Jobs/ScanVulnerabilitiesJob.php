<?php

namespace App\Jobs;

use App\Domain\Security\VulnerabilityFeed;
use App\Models\Hypervisor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sprawdzenie pakietów węzła w bazie luk — po „Sprawdź teraz”, bez trzymania żądania. */
class ScanVulnerabilitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly ?int $hypervisorId = null) {}

    public function handle(VulnerabilityFeed $feed): void
    {
        $feed->scan($this->hypervisorId === null ? null : Hypervisor::query()->whereKey($this->hypervisorId)->get());
    }
}
