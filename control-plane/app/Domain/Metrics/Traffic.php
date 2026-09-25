<?php

namespace App\Domain\Metrics;

use App\Domain\Provisioning\ServerProvisioner;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerTraffic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Transfer maszyn w miesiącu kalendarzowym i blokada po przekroczeniu limitu.
 *
 * Liczymy z przyrostów liczników karty sieciowej między próbkami (co minutę).
 * Liczniki są narastające, więc przerwa w zbieraniu nie gubi ruchu. Spadek
 * licznika oznacza restart maszyny — wtedy liczymy jego nową wartość.
 *
 * Jednostki dziesiętne, jak u dostawców łącz: 1 GB = 1000³ B.
 */
class Traffic
{
    public const GB = 1_000_000_000;

    public function __construct(private readonly ServerProvisioner $provisioner) {}

    public static function periodStart(?\DateTimeInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::parse($at ?? now())->startOfMonth();
    }

    /**
     * @param  array{net_rx_bytes?: int, net_tx_bytes?: int}|null  $previous  liczniki z poprzedniej próbki
     * @param  array{net_rx_bytes?: int, net_tx_bytes?: int}  $current
     */
    public function record(Server $server, ?array $previous, array $current): void
    {
        if ($previous === null) {
            return; // pierwsza próbka — nie ma od czego liczyć przyrostu
        }

        $delta = fn (string $key) => ($now = (int) ($current[$key] ?? 0)) >= ($before = (int) ($previous[$key] ?? 0))
            ? $now - $before
            : $now; // licznik spadł — maszyna wystartowała od nowa

        $rx = $delta('net_rx_bytes');
        $tx = $delta('net_tx_bytes');

        if ($rx === 0 && $tx === 0) {
            return;
        }

        $period = self::periodStart()->toDateString();
        // Atomowo — dwa równoległe obiegi nie zgubią przyrostu.
        DB::transaction(function () use ($server, $period, $rx, $tx) {
            // whereDate: kolumna daty bywa zapisana z godziną (SQLite), więc
            // porównanie wprost z '2026-09-01' by jej nie znalazło.
            $row = ServerTraffic::query()->lockForUpdate()
                ->where('server_id', $server->id)
                ->whereDate('period_start', $period)
                ->first()
                ?? ServerTraffic::create(['server_id' => $server->id, 'period_start' => $period]);
            $row->forceFill([
                'rx_bytes' => $row->rx_bytes + $rx,
                'tx_bytes' => $row->tx_bytes + $tx,
            ])->save();
        });
    }

    /**
     * @return array{period_start: string, resets_at: string, rx: int, tx: int, used: int, limit: int|null, percent: float|null, counting: string, blocked: bool}
     */
    public function usage(Server $server): array
    {
        $period = self::periodStart();
        $row = ServerTraffic::query()
            ->where('server_id', $server->id)
            ->whereDate('period_start', $period->toDateString())
            ->first();

        $rx = $row?->rx_bytes ?? 0;
        $tx = $row?->tx_bytes ?? 0;
        $counting = self::counting();
        $used = match ($counting) {
            'in' => $rx,
            'out' => $tx,
            default => $rx + $tx,
        };
        $limit = $server->bandwidth_gb > 0 ? $server->bandwidth_gb * self::GB : null;

        return [
            'period_start' => $period->toDateString(),
            'resets_at' => $period->addMonth()->toDateString(),
            'rx' => $rx,
            'tx' => $tx,
            'used' => $used,
            'limit' => $limit,
            'percent' => $limit ? round(min(100, $used / $limit * 100), 1) : null,
            'counting' => $counting,
            'blocked' => $server->traffic_blocked_at !== null,
        ];
    }

    /** Zawiesza maszynę, która przekroczyła limit. Zwraca true, gdy właśnie ją zablokował. */
    public function enforce(Server $server): bool
    {
        if ($server->isSuspended() || $server->bandwidth_gb <= 0) {
            return false;
        }

        $usage = $this->usage($server);
        if ($usage['used'] < $usage['limit']) {
            return false;
        }

        $server->forceFill(['traffic_blocked_at' => now()])->save();
        $this->provisioner->suspend($server, sprintf(
            'Przekroczono limit transferu: %s z %s. Maszyna zostanie odblokowana %s albo po zwiększeniu limitu.',
            self::human($usage['used']),
            self::human($usage['limit']),
            CarbonImmutable::parse($usage['resets_at'])->format('d.m.Y'),
        ));

        return true;
    }

    /**
     * Odblokowuje maszyny zawieszone za transfer, jeśli już się mieszczą
     * w limicie (nowy miesiąc, zwiększony limit, wyzerowany licznik).
     */
    public function releaseEligible(?Server $only = null): int
    {
        $released = 0;
        $query = Server::query()->whereNotNull('traffic_blocked_at');
        if ($only !== null) {
            $query->whereKey($only->id);
        }

        foreach ($query->get() as $server) {
            $usage = $this->usage($server);
            if ($usage['limit'] !== null && $usage['used'] >= $usage['limit']) {
                continue;
            }

            $server->forceFill(['traffic_blocked_at' => null])->save();
            // Zawieszenie za płatność ma pierwszeństwo — zdejmujemy tylko nasze.
            if ($server->isSuspended() && str_starts_with((string) $server->suspension_reason, 'Przekroczono limit transferu')) {
                $this->provisioner->unsuspend($server);
            }
            $released++;
        }

        return $released;
    }

    /** Wyzerowanie licznika bieżącego okresu przez administratora. */
    public function reset(Server $server, ?User $actor = null): void
    {
        ServerTraffic::query()
            ->where('server_id', $server->id)
            ->whereDate('period_start', self::periodStart()->toDateString())
            ->update(['rx_bytes' => 0, 'tx_bytes' => 0]);

        AuditLog::record('server.traffic_reset', $server, [], $actor);
        $this->releaseEligible($server);
    }

    /** Co wlicza się do limitu: ruch w obie strony (domyślnie), tylko wychodzący albo przychodzący. */
    public static function counting(): string
    {
        $mode = (string) config('virthub.traffic_counting', 'total');

        return in_array($mode, ['in', 'out', 'total'], true) ? $mode : 'total';
    }

    public static function human(?int $bytes): string
    {
        if ($bytes === null) {
            return 'bez limitu';
        }
        foreach (['TB' => 1e12, 'GB' => 1e9, 'MB' => 1e6] as $unit => $size) {
            if ($bytes >= $size) {
                $value = $bytes / $size;

                return str_replace('.', ',', (string) round($value, $value >= 100 ? 0 : ($value >= 10 ? 1 : 2))).' '.$unit;
            }
        }

        return str_replace('.', ',', (string) round($bytes / 1e6, 2)).' MB';
    }
}
