<?php

namespace App\Jobs;

use App\Domain\External\ExternalServerManager;
use App\Models\ExternalServer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Zamawia maszynę u dostawcy zewnętrznego; dalszy stan odpytuje harmonogram. */
class ProvisionExternalServerJob implements ShouldQueue
{
    use Queueable;

    // Jedna próba: ponowienie po błędzie mogłoby zamówić (i opłacić) dwie maszyny.
    public int $tries = 1;

    public int $timeout = 120;

    /** @param  list<string>  $sshKeys */
    public function __construct(public readonly int $serverId, public readonly array $sshKeys = []) {}

    public function handle(ExternalServerManager $manager): void
    {
        $server = ExternalServer::query()->with('account')->find($this->serverId);
        if ($server === null || $server->remote_id !== null) {
            return;
        }
        rescue(fn () => $manager->provision($server, $this->sshKeys), report: false);
    }
}
