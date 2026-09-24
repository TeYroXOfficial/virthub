<?php

namespace App\Console\Commands;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Metrics\ServerMetrics;
use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Console\Command;

/**
 * Zbieranie telemetrii z działających maszyn.
 *
 * Odpytujemy per hypervisor, nie per maszyna, żeby jedno połączenie obsłużyło
 * wszystkie VPS-y na węźle. CPU i szybkości dysku/sieci liczymy z różnicy
 * liczników narastających między próbkami (ServerMetrics::rates).
 */
class CollectServerMetrics extends Command
{
    protected $signature = 'virthub:collect-metrics';

    protected $description = 'Pobiera zużycie zasobów działających maszyn i zapisuje próbki';

    public function handle(): int
    {
        $collected = 0;
        $guestOs = app(\App\Domain\Provisioning\GuestOs::class);

        Hypervisor::query()->where('status', Hypervisor::STATUS_ONLINE)->each(
            function (Hypervisor $hypervisor) use (&$collected, $guestOs) {
                $client = new AgentClient($hypervisor);

                $servers = Server::query()
                    ->where('hypervisor_id', $hypervisor->id)
                    ->where('state', ServerState::Running->value)
                    ->whereNotNull('agent_uuid')
                    ->get();

                foreach ($servers as $server) {
                    try {
                        $stats = $client->stats($server->agent_uuid);
                    } catch (AgentException $e) {
                        $this->warn("VPS {$server->id}: {$e->getMessage()}");

                        continue;
                    }

                    $this->store($server, $stats);
                    $collected++;

                    // System w maszynie sprawdzamy rzadziej — zmienia się tylko przy
                    // reinstalacji albo instalacji z ISO.
                    if ($guestOs->isDue($server)) {
                        $guestOs->detect($server->setRelation('hypervisor', $hypervisor));
                    }
                }
            }
        );

        $this->info("Zapisano {$collected} próbek.");

        return self::SUCCESS;
    }

    private function store(Server $server, array $stats): void
    {
        $previous = ServerMetric::query()
            ->where('server_id', $server->id)
            ->latest('sampled_at')
            ->first();

        $now = now();
        // Poprzednia próbka sprzed ponad kwadransa (maszyna była wyłączona,
        // węzeł niedostępny) nie nadaje się do liczenia szybkości — uśredniłaby
        // ruch z całej przerwy.
        $usable = $previous !== null && $previous->sampled_at->gt($now->copy()->subMinutes(15));

        $rates = ServerMetrics::rates(
            $stats,
            $usable ? $previous->only(['cpu_time_ns', 'disk_read_bytes', 'disk_write_bytes', 'net_rx_bytes', 'net_tx_bytes']) : null,
            $usable ? max(1, $previous->sampled_at->diffInSeconds($now, absolute: true)) : 0,
            $server->vcpu,
        );

        ServerMetric::create([
            'server_id' => $server->id,
            'sampled_at' => $now,
            ...$rates,
            'cpu_time_ns' => $stats['cpu_time_ns'] ?? 0,
            'ram_used_mb' => $stats['ram_used_mb'] ?? 0,
            'ram_total_mb' => $stats['ram_total_mb'] ?? 0,
            'disk_read_bytes' => $stats['disk_read_bytes'] ?? 0,
            'disk_write_bytes' => $stats['disk_write_bytes'] ?? 0,
            'net_rx_bytes' => $stats['net_rx_bytes'] ?? 0,
            'net_tx_bytes' => $stats['net_tx_bytes'] ?? 0,
        ]);

        $server->forceFill(['last_synced_at' => $now])->save();
    }
}
