<?php

namespace App\Console\Commands;

use App\Domain\Security\VulnerabilityFeed;
use App\Models\Hypervisor;
use App\Models\PackageVulnerabilityScan;
use Illuminate\Console\Command;

/** Sprawdza jądro, QEMU, libvirt i Incusa na węzłach w bazie luk OSV.dev. */
class ScanVulnerabilities extends Command
{
    protected $signature = 'virthub:scan-vulnerabilities
        {--if-pending : Tylko gdy jakiś pakiet nie był jeszcze sprawdzony albo brakuje opisów luk}';

    protected $description = 'Sprawdza pakiety węzłów w bazie luk OSV.dev';

    public function handle(VulnerabilityFeed $feed): int
    {
        if ($this->option('if-pending') && ! $this->pending($feed)) {
            return self::SUCCESS;
        }
        $result = $feed->scan();
        $this->info("Sprawdzono {$result['packages']} wersji pakietów.");

        return self::SUCCESS;
    }

    /** Nowa wersja pakietu na węźle (aktualizacja, nowy węzeł) albo niedokończone pobieranie opisów. */
    private function pending(VulnerabilityFeed $feed): bool
    {
        foreach (Hypervisor::query()->whereNotNull('enrolled_at')->get() as $node) {
            foreach (VulnerabilityFeed::packagesOf($node) as $p) {
                if ($p['ecosystem'] !== null && ! PackageVulnerabilityScan::query()
                    ->where(['ecosystem' => $p['ecosystem'], 'package' => $p['name'], 'version' => $p['version']])->exists()) {
                    return true;
                }
            }
            [$done, $total] = $feed->progress($node);
            if ($done < $total) {
                return true;
            }
        }

        return false;
    }
}
