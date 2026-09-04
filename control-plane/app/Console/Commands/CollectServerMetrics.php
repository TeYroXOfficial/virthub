<?php

namespace App\Console\Commands;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Console\Command;

/**
 * Zbieranie telemetrii z działających maszyn.
 *
 * Odpytujemy per hypervisor, nie per maszyna, żeby jedno połączenie obsłużyło
 * wszystkie VPS-y na węźle. CPU liczymy z różnicy czasu procesora między
 * próbkami — libvirt podaje licznik narastający, nie procent.
 */
class CollectServerMetrics extends Command
{
    protected $signature = 'virthub:collect-metrics';

    protected $description = 'Pobiera zużycie zasobów działających maszyn i zapisuje próbki';

    public function handle(): int
    {
        $collected = 0;

        Hypervisor::query()->where('status', Hypervisor::STATUS_ONLINE)->each(
            function (Hypervisor $hypervisor) use (&$collected) {
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

        ServerMetric::create([
            'server_id' => $server->id,
            'sampled_at' => now(),
            'cpu_percent' => $this->cpuPercent($stats, $previous, $server->vcpu),
            'cpu_time_ns' => $stats['cpu_time_ns'] ?? 0,
            'ram_used_mb' => $stats['ram_used_mb'] ?? 0,
            'disk_read_bytes' => $stats['disk_read_bytes'] ?? 0,
            'disk_write_bytes' => $stats['disk_write_bytes'] ?? 0,
            'net_rx_bytes' => $stats['net_rx_bytes'] ?? 0,
            'net_tx_bytes' => $stats['net_tx_bytes'] ?? 0,
        ]);

        $server->forceFill(['last_synced_at' => now()])->save();
    }

    private function cpuPercent(array $stats, ?ServerMetric $previous, int $vcpu): float
    {
        // Agent w trybie mock podaje procent wprost — nie ma z czego liczyć różnicy.
        if (isset($stats['cpu_percent']) && $stats['cpu_percent'] > 0) {
            return (float) $stats['cpu_percent'];
        }

        $cpuTimeNs = (int) ($stats['cpu_time_ns'] ?? 0);

        // Pierwsza próbka po starcie maszyny nie ma z czym się porównać.
        if ($previous === null || $cpuTimeNs === 0 || $previous->cpu_time_ns === 0) {
            return 0.0;
        }

        $deltaNs = $cpuTimeNs - $previous->cpu_time_ns;

        // Licznik zresetowany (maszyna była restartowana) — pomijamy próbkę
        // zamiast raportować ujemne albo absurdalnie wysokie zużycie.
        if ($deltaNs <= 0) {
            return 0.0;
        }

        $elapsedSeconds = max(1, now()->diffInSeconds($previous->sampled_at, absolute: true));
        $percent = ($deltaNs / 1_000_000_000) / ($elapsedSeconds * max(1, $vcpu)) * 100;

        return round(min(100.0, max(0.0, $percent)), 2);
    }
}
