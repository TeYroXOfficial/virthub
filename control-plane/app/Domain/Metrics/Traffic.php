<?php

namespace App\Domain\Metrics;

use App\Domain\Provisioning\ServerProvisioner;
use App\Domain\Webhooks\Webhooks;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerTraffic;
use App\Models\TrafficBlock;
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
 * Jednostki dziesiętne, jak u dostawców łącz (1 GB = 1000³ B), albo binarne
 * (VH_TRAFFIC_UNITS=binary, 1 GB = 1024³ B).
 *
 * Polityka (kierunek liczenia, akcja po limicie, prędkość dławienia)
 * dziedziczy: maszyna → pakiet → węzeł → ustawienia globalne. Po limicie
 * maszyna jest blokowana (zawieszana) albo dławiona do zadanej prędkości.
 * Dokupione pakiety transferu powiększają limit w swoim okresie.
 */
class Traffic
{
    public const GB = 1_000_000_000;

    public const COUNTING = ['in', 'out', 'total'];

    public const ACTIONS = ['block', 'throttle'];

    /** Powód zawieszenia za transfer (klucz tłumaczenia, argumenty sprintf). */
    private const BLOCK_REASON = 'Przekroczono limit transferu: %s z %s. Maszyna zostanie odblokowana %s albo po zwiększeniu limitu.';

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
        [$rx, $tx] = $this->counters($server, $period);
        $policy = self::policy($server);
        $counting = $policy['counting'];
        $used = self::counted($counting, $rx, $tx);
        $extraGb = (int) $server->trafficBlocks()->whereDate('period_start', $period->toDateString())->sum('gb');
        $limit = $server->bandwidth_gb > 0 ? ($server->bandwidth_gb + $extraGb) * self::unitBytes() : null;

        // Pakiet zasobów ze wspólnym transferem: limit i zużycie liczone łącznie dla wszystkich jego maszyn.
        $pack = $server->resource_pack_id ? $server->resourcePack : null;
        $pool = null;
        if ($pack?->poolsTraffic()) {
            $members = $pack->servers()->get();
            $used = $members->sum(fn (Server $s) => self::counted(self::policy($s)['counting'], ...$this->counters($s, $period)));
            $extraGb = (int) TrafficBlock::query()->whereIn('server_id', $members->modelKeys())->whereDate('period_start', $period->toDateString())->sum('gb');
            $limit = ((int) $pack->traffic_gb + $extraGb) * self::unitBytes();
            $pool = ['pack' => $pack->name, 'servers' => $members->count(), 'own' => self::counted($counting, $rx, $tx)];
        }

        return [
            'period_start' => $period->toDateString(),
            'resets_at' => $period->addMonth()->toDateString(),
            'rx' => $rx,
            'tx' => $tx,
            'used' => $used,
            'limit' => $limit,
            'extra_gb' => $extraGb,
            'percent' => $limit ? round(min(100, $used / $limit * 100), 1) : null,
            'counting' => $counting,
            'action' => $policy['action'],
            'blocked' => $server->traffic_blocked_at !== null,
            'throttled' => $server->traffic_throttled_at !== null,
            'throttle_mbps' => $policy['throttle_mbps'],
            'pool' => $pool,
        ];
    }

    /** @return array{0: int, 1: int} [rx, tx] w bieżącym okresie */
    private function counters(Server $server, CarbonImmutable $period): array
    {
        $row = ServerTraffic::query()->where('server_id', $server->id)->whereDate('period_start', $period->toDateString())->first();

        return [(int) ($row?->rx_bytes ?? 0), (int) ($row?->tx_bytes ?? 0)];
    }

    private static function counted(string $counting, int $rx, int $tx): int
    {
        return match ($counting) {
            'in' => $rx,
            'out' => $tx,
            default => $rx + $tx,
        };
    }

    /** Blokuje albo dławi maszynę, która przekroczyła limit. Zwraca true, gdy właśnie to zrobił. */
    public function enforce(Server $server, bool $cascade = true): bool
    {
        if ($server->isSuspended() || $server->traffic_throttled_at !== null) {
            return false;
        }

        $usage = $this->usage($server);
        if ($usage['limit'] === null || $usage['used'] < $usage['limit']) {
            return false;
        }

        if ($usage['action'] === 'throttle') {
            $server->forceFill(['traffic_throttled_at' => now()])->save();
            AuditLog::record('server.traffic_throttled', $server, ['mbps' => $usage['throttle_mbps'], 'used' => $usage['used']]);
            $this->syncBandwidth($server);
            $webhooks = app(Webhooks::class);
            $webhooks->dispatch('server.traffic_throttled', $webhooks->describe($server) + ['throttle_mbps' => $usage['throttle_mbps'],
                'used_bytes' => $usage['used'], 'limit_bytes' => $usage['limit'], 'resets_at' => $usage['resets_at']]);
            $this->enforcePool($server, $usage, $cascade);

            return true;
        }

        $server->forceFill(['traffic_blocked_at' => now()])->save();
        $this->provisioner->suspend($server, sprintf(
            __(self::BLOCK_REASON),
            self::human($usage['used']),
            self::human($usage['limit']),
            CarbonImmutable::parse($usage['resets_at'])->format('d.m.Y'),
        ));
        $this->enforcePool($server, $usage, $cascade);

        return true;
    }

    /** Wspólny transfer wyczerpany — ta sama akcja od razu na pozostałych maszynach pakietu. */
    private function enforcePool(Server $server, array $usage, bool $cascade): void
    {
        if (! $cascade || $usage['pool'] === null) {
            return;
        }
        Server::query()->where('resource_pack_id', $server->resource_pack_id)->whereKeyNot($server->id)->get()
            ->each(fn (Server $other) => $this->enforce($other, false));
    }

    /**
     * Odblokowuje maszyny zawieszone za transfer, jeśli już się mieszczą
     * w limicie (nowy miesiąc, zwiększony limit, wyzerowany licznik).
     */
    public function releaseEligible(?Server $only = null): int
    {
        $released = 0;
        $query = Server::query()->where(fn ($q) => $q->whereNotNull('traffic_blocked_at')->orWhereNotNull('traffic_throttled_at'));
        if ($only !== null) {
            // Wspólny transfer: zmiana na jednej maszynie dotyczy całego pakietu.
            $only->resource_pack_id !== null && $only->resourcePack?->poolsTraffic()
                ? $query->where('resource_pack_id', $only->resource_pack_id)
                : $query->whereKey($only->id);
        }

        foreach ($query->get() as $server) {
            $usage = $this->usage($server);
            if ($usage['limit'] !== null && $usage['used'] >= $usage['limit']) {
                continue;
            }

            if ($server->traffic_throttled_at !== null) {
                $server->forceFill(['traffic_throttled_at' => null])->save();
                AuditLog::record('server.traffic_unthrottled', $server, []);
                $this->syncBandwidth($server);
                $webhooks = app(Webhooks::class);
                $webhooks->dispatch('server.traffic_unthrottled', $webhooks->describe($server));
                $released++;
                if ($server->traffic_blocked_at === null) {
                    continue;
                }
            }

            $server->forceFill(['traffic_blocked_at' => null])->save();
            // Zawieszenie za płatność ma pierwszeństwo — zdejmujemy tylko nasze.
            if ($server->isSuspended() && self::isTrafficSuspension((string) $server->suspension_reason)) {
                $this->provisioner->unsuspend($server);
            }
            $released++;
        }

        return $released;
    }

    /** Powód zawieszenia zapisany przez enforce() — w dowolnym języku panelu. */
    private static function isTrafficSuspension(string $reason): bool
    {
        foreach (array_keys(config('virthub.locales')) as $locale) {
            $prefix = strstr(__(self::BLOCK_REASON, [], $locale), ':', true);
            if ($prefix !== false && str_starts_with($reason, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Dokupiony transfer w bieżącym okresie — powiększa limit, zdejmuje blokadę albo dławienie. */
    public function addBlock(Server $server, int $gb, ?string $note = null, ?User $actor = null): TrafficBlock
    {
        $block = $server->trafficBlocks()->create([
            'period_start' => self::periodStart()->toDateString(), 'gb' => $gb, 'note' => $note, 'user_id' => $actor?->id,
        ]);
        AuditLog::record('server.traffic_block_added', $server, ['gb' => $gb, 'note' => $note], $actor);
        $this->releaseEligible($server);

        return $block;
    }

    public function removeBlock(TrafficBlock $block, ?User $actor = null): void
    {
        AuditLog::record('server.traffic_block_removed', $block->server, ['gb' => $block->gb], $actor);
        $block->delete();
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

    /**
     * Polityka po dziedziczeniu: maszyna → pakiet → węzeł → globalne.
     *
     * @return array{counting: string, action: string, throttle_mbps: int}
     */
    public static function policy(Server $server): array
    {
        $chain = [$server, $server->package, $server->hypervisor];
        $pick = function (string $field, mixed $default) use ($chain): mixed {
            foreach ($chain as $model) {
                if ($model !== null && $model->{$field} !== null && $model->{$field} !== '') {
                    return $model->{$field};
                }
            }

            return $default;
        };

        return [
            'counting' => in_array($c = $pick('traffic_counting', self::counting()), self::COUNTING, true) ? $c : 'total',
            'action' => in_array($a = $pick('traffic_limit_action', config('virthub.traffic_limit_action', 'block')), self::ACTIONS, true) ? $a : 'block',
            'throttle_mbps' => max(1, (int) $pick('traffic_throttle_mbps', config('virthub.traffic_throttle_mbps', 10))),
        ];
    }

    /** Limit prędkości karty: dławienie po limicie albo stała prędkość portu (maszyna → pakiet). Null = bez limitu. */
    public static function bandwidthMbps(Server $server): ?int
    {
        if ($server->traffic_throttled_at !== null) {
            return self::policy($server)['throttle_mbps'];
        }
        $port = $server->port_speed_mbps ?? $server->package?->port_speed_mbps;

        return $port ? (int) $port : null;
    }

    /** Bajty w „GB” limitu: dziesiętnie (1000³, domyślnie) albo binarnie (1024³). */
    public static function unitBytes(): int
    {
        return config('virthub.traffic_units') === 'binary' ? 1024 ** 3 : self::GB;
    }

    private function syncBandwidth(Server $server): void
    {
        if ($server->agent_uuid !== null && ! $server->isSuspended()) {
            $this->provisioner->syncNetwork($server);
        }
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
        $units = config('virthub.traffic_units') === 'binary' ? ['TiB' => 1024 ** 4, 'GiB' => 1024 ** 3, 'MiB' => 1024 ** 2] : ['TB' => 1e12, 'GB' => 1e9, 'MB' => 1e6];
        foreach ($units as $unit => $size) {
            if ($bytes >= $size) {
                $value = $bytes / $size;

                return str_replace('.', ',', (string) round($value, $value >= 100 ? 0 : ($value >= 10 ? 1 : 2))).' '.$unit;
            }
        }

        return str_replace('.', ',', (string) round($bytes / 1e6, 2)).' MB';
    }
}
