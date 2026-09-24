<?php

namespace App\Domain\Metrics;

use App\Domain\Agent\AgentClient;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Telemetria maszyn: odczyt na żywo i historia w zakresach godzina/doba/tydzień.
 *
 * Agent podaje liczniki narastające (czas procesora, bajty dysku i sieci).
 * Zużycie w czasie to różnica dwóch odczytów podzielona przez czas między
 * nimi — ta sama metoda dla próbek zapisywanych co minutę i dla podglądu na
 * żywo, więc oba wykresy mówią to samo.
 */
class ServerMetrics
{
    /** Zakres → [długość w sekundach, szerokość kubełka w sekundach]. */
    public const RANGES = [
        'hour' => [3600, 60],
        'day' => [86400, 600],
        'week' => [604800, 3600],
    ];

    private const COUNTERS = ['disk_read_bytes', 'disk_write_bytes', 'net_rx_bytes', 'net_tx_bytes'];

    /**
     * Zużycie między dwoma odczytami liczników.
     *
     * @param  array<string, mixed>  $current  odczyt agenta
     * @param  array<string, mixed>|null  $previous  poprzedni odczyt (te same klucze)
     * @return array{cpu_percent: float, disk_read_bps: int, disk_write_bps: int, net_rx_bps: int, net_tx_bps: int}
     */
    public static function rates(array $current, ?array $previous, float $elapsedSeconds, int $vcpu): array
    {
        $rates = [
            'cpu_percent' => 0.0,
            'disk_read_bps' => 0,
            'disk_write_bps' => 0,
            'net_rx_bps' => 0,
            'net_tx_bps' => 0,
        ];

        // Agent w trybie mock podaje procent wprost — nie ma z czego liczyć różnicy.
        if (($current['cpu_percent'] ?? 0) > 0) {
            $rates['cpu_percent'] = round((float) $current['cpu_percent'], 2);
        }

        if ($previous === null || $elapsedSeconds <= 0) {
            return $rates;
        }

        $cpuDelta = (int) ($current['cpu_time_ns'] ?? 0) - (int) ($previous['cpu_time_ns'] ?? 0);
        if ($rates['cpu_percent'] === 0.0 && $cpuDelta > 0 && (int) ($previous['cpu_time_ns'] ?? 0) > 0) {
            $percent = ($cpuDelta / 1e9) / ($elapsedSeconds * max(1, $vcpu)) * 100;
            $rates['cpu_percent'] = round(min(100.0, max(0.0, $percent)), 2);
        }

        foreach (self::COUNTERS as $counter) {
            $delta = (int) ($current[$counter] ?? 0) - (int) ($previous[$counter] ?? 0);
            // Ujemna różnica = licznik wyzerowany (restart maszyny). Pomijamy
            // próbkę zamiast pokazywać absurdalny skok.
            $key = str_replace('_bytes', '_bps', $counter);
            $rates[$key] = $delta > 0 ? (int) round($delta / $elapsedSeconds) : 0;
        }

        return $rates;
    }

    /**
     * Bieżące zużycie prosto z agenta. Poprzedni odczyt trzymamy w cache —
     * przeglądarka odpytuje co kilka sekund, więc szybkość liczymy z tego
     * krótkiego odcinka, a nie z minutowej próbki w bazie.
     *
     * @return array<string, mixed>
     */
    public function live(Server $server): array
    {
        $stats = (new AgentClient($server->hypervisor))->stats($server->agent_uuid);
        $now = microtime(true);

        $key = "metrics-live:{$server->id}";
        $previous = Cache::get($key);

        if ($previous === null) {
            // Pierwszy odczyt: porównujemy z ostatnią próbką z bazy, żeby
            // wykres od razu miał sensowną wartość zamiast zera.
            $last = ServerMetric::query()->where('server_id', $server->id)->latest('sampled_at')->first();
            if ($last !== null && $last->sampled_at->gt(now()->subMinutes(5))) {
                $previous = ['t' => $last->sampled_at->getTimestamp(), 'stats' => $last->only([
                    'cpu_time_ns', ...self::COUNTERS,
                ])];
            }
        }

        Cache::put($key, ['t' => $now, 'stats' => $stats], now()->addMinutes(2));

        $rates = self::rates($stats, $previous['stats'] ?? null, $previous ? $now - $previous['t'] : 0, $server->vcpu);

        return [
            't' => round($now, 3),
            'state' => $stats['state'] ?? null,
            ...$rates,
            'ram_used_mb' => (int) ($stats['ram_used_mb'] ?? 0),
            'ram_total_mb' => (int) ($stats['ram_total_mb'] ?? 0) ?: $server->ram_mb,
        ];
    }

    /**
     * Historia uśredniona w kubełkach. Brak próbek w kubełku (maszyna
     * wyłączona, węzeł niedostępny) to null — wykres pokazuje przerwę, a nie
     * spadek do zera, który wyglądałby jak bezczynność.
     *
     * @return array<string, mixed>
     */
    public function history(Server $server, string $range): array
    {
        [$span, $bucket] = self::RANGES[$range] ?? self::RANGES['day'];

        $end = (int) (floor(now()->getTimestamp() / $bucket) * $bucket) + $bucket;
        $start = $end - $span;

        $samples = ServerMetric::query()
            ->where('server_id', $server->id)
            ->where('sampled_at', '>=', Carbon::createFromTimestamp($start))
            ->orderBy('sampled_at')
            ->get(['sampled_at', 'cpu_percent', 'ram_used_mb', 'disk_read_bps', 'disk_write_bps', 'net_rx_bps', 'net_tx_bps']);

        $fields = ['cpu_percent', 'ram_used_mb', 'disk_read_bps', 'disk_write_bps', 'net_rx_bps', 'net_tx_bps'];
        $sums = [];
        $counts = [];

        foreach ($samples as $sample) {
            $index = intdiv($sample->sampled_at->getTimestamp() - $start, $bucket);
            $counts[$index] = ($counts[$index] ?? 0) + 1;
            foreach ($fields as $field) {
                $sums[$field][$index] = ($sums[$field][$index] ?? 0) + (float) $sample->{$field};
            }
        }

        $buckets = intdiv($span, $bucket);
        $series = ['t' => []] + array_fill_keys($fields, []);

        for ($i = 0; $i < $buckets; $i++) {
            $series['t'][] = $start + $i * $bucket;
            foreach ($fields as $field) {
                $series[$field][] = isset($counts[$i])
                    ? round($sums[$field][$i] / $counts[$i], $field === 'cpu_percent' ? 2 : 0)
                    : null;
            }
        }

        return [
            'range' => array_search([$span, $bucket], self::RANGES, true) ?: 'day',
            'bucket_seconds' => $bucket,
            'ram_total_mb' => $server->ram_mb,
            'samples' => $samples->count(),
            'series' => $series,
        ];
    }
}
