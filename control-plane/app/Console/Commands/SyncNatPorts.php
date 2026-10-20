<?php

namespace App\Console\Commands;

use App\Domain\Provisioning\ServerProvisioner;
use App\Models\IpPool;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Wysyła na węzły aktualne przekierowania portów wszystkich maszyn za NAT-em —
 * np. po aktualizacji, w której nieustawione porty przestały przechodzić 1:1.
 */
class SyncNatPorts extends Command
{
    protected $signature = 'virthub:sync-nat-ports';

    protected $description = 'Przeładowuje przekierowania portów NAT na wszystkich maszynach';

    public function handle(ServerProvisioner $provisioner): int
    {
        $count = 0;
        Server::query()->active()->whereNotNull('agent_uuid')
            ->whereHas('ipAddresses.pool', fn ($q) => $q->where('type', IpPool::TYPE_NAT))
            ->each(function (Server $server) use ($provisioner, &$count) {
                if ($server->acceptsCommands()) {
                    $provisioner->syncNetwork($server);
                    $count++;
                }
            });

        $this->info("Zlecono przeładowanie sieci dla {$count} maszyn.");

        return self::SUCCESS;
    }
}
