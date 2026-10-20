<?php

namespace App\Console\Commands;

use App\Domain\Security\VulnerabilityFeed;
use Illuminate\Console\Command;

/** Sprawdza jądro, QEMU, libvirt i Incusa na węzłach w bazie luk OSV.dev. */
class ScanVulnerabilities extends Command
{
    protected $signature = 'virthub:scan-vulnerabilities';

    protected $description = 'Sprawdza pakiety węzłów w bazie luk OSV.dev';

    public function handle(VulnerabilityFeed $feed): int
    {
        $count = $feed->scan();
        $this->info("Sprawdzono {$count} wersji pakietów.");

        return self::SUCCESS;
    }
}
