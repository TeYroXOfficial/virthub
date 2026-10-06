<?php

namespace App\Domain\Apps;

use App\Models\AppAllocation;
use App\Models\AppServer;
use App\Models\Hypervisor;
use App\Models\IpPool;
use Illuminate\Support\Facades\DB;

/**
 * Porty aplikacji na węźle — z zakresu ustawionego przez administratora,
 * z pominięciem bloków portów NAT maszyn (te same porty węzła).
 */
class AppPorts
{
    /** Porty zajęte na węźle przez bloki NAT pul, z których korzysta. */
    public function natSpans(Hypervisor $node): array
    {
        return IpPool::query()->usableBy($node)->where('type', IpPool::TYPE_NAT)->get()
            ->map(fn (IpPool $pool) => $pool->natPortSpan())
            ->filter()
            ->values()
            ->all();
    }

    public function freeCount(Hypervisor $node): int
    {
        if (! $node->app_port_start || ! $node->app_port_end) {
            return 0;
        }
        $used = AppAllocation::query()->where('hypervisor_id', $node->id)->whereNotNull('app_server_id')->count();
        $blocked = 0;
        foreach ($this->natSpans($node) as $span) {
            $blocked += max(0, min($span['to'], $node->app_port_end) - max($span['from'], $node->app_port_start) + 1);
        }

        return max(0, $node->app_port_end - $node->app_port_start + 1 - $blocked - $used);
    }

    /**
     * Przydziela kolejne wolne porty. Pierwszy zostaje portem głównym
     * (SERVER_PORT w aplikacji).
     *
     * @return list<AppAllocation>
     */
    public function allocate(AppServer $app, Hypervisor $node, int $count): array
    {
        return DB::transaction(function () use ($app, $node, $count) {
            $taken = AppAllocation::query()
                ->where('hypervisor_id', $node->id)
                ->whereNotNull('app_server_id')
                ->lockForUpdate()
                ->pluck('port')
                ->flip();
            $spans = $this->natSpans($node);

            $ports = [];
            for ($port = $node->app_port_start; $port <= $node->app_port_end && count($ports) < $count; $port++) {
                if (isset($taken[$port])) {
                    continue;
                }
                foreach ($spans as $span) {
                    if ($port >= $span['from'] && $port <= $span['to']) {
                        continue 2;
                    }
                }
                $ports[] = $port;
            }

            if (count($ports) < $count) {
                throw new \DomainException(__('Na węźle :name zabrakło wolnych portów dla aplikacji.', ['name' => $node->name]));
            }

            return array_map(fn (int $port, int $i) => AppAllocation::query()->updateOrCreate(
                ['hypervisor_id' => $node->id, 'port' => $port],
                ['app_server_id' => $app->id, 'is_primary' => $i === 0],
            ), $ports, array_keys($ports));
        });
    }

    /** Ile portów może mieć aplikacja: limit aplikacji, inaczej planu. */
    public function limit(AppServer $app): int
    {
        return (int) ($app->port_limit ?? $app->plan?->ports ?? max(1, $app->allocations()->count()));
    }

    /**
     * Dodaje port: konkretny (tylko personel) albo kolejny wolny z zakresu węzła.
     * Klient dodaje porty do limitu, personel także ponad niego.
     */
    public function add(AppServer $app, ?int $port = null, bool $staff = false): AppAllocation
    {
        $node = $app->hypervisor ?? throw new \DomainException(__('Aplikacja nie jest przypisana do węzła.'));
        if (! $staff && $app->allocations()->count() >= $this->limit($app)) {
            throw new \DomainException(__('Osiągnięto limit :limit portów tej aplikacji.', ['limit' => $this->limit($app)]));
        }
        if ($port === null) {
            $allocation = $this->allocate($app, $node, 1)[0];
            $allocation->update(['is_primary' => ! $app->allocations()->whereKeyNot($allocation->id)->exists()]);

            return $allocation;
        }

        return DB::transaction(function () use ($app, $node, $port) {
            if (! $node->app_port_start || $port < $node->app_port_start || $port > $node->app_port_end) {
                throw new \DomainException(__('Port :port jest poza zakresem portów aplikacji na węźle (:from–:to).', ['port' => $port, 'from' => $node->app_port_start, 'to' => $node->app_port_end]));
            }
            foreach ($this->natSpans($node) as $span) {
                if ($port >= $span['from'] && $port <= $span['to']) {
                    throw new \DomainException(__('Port :port należy do bloku portów NAT maszyn.', ['port' => $port]));
                }
            }
            $taken = AppAllocation::query()->where('hypervisor_id', $node->id)->where('port', $port)->lockForUpdate()->first();
            if ($taken?->app_server_id !== null) {
                throw new \DomainException(__('Port :port jest już zajęty.', ['port' => $port]));
            }

            return AppAllocation::query()->updateOrCreate(
                ['hypervisor_id' => $node->id, 'port' => $port],
                ['app_server_id' => $app->id, 'is_primary' => ! $app->allocations()->exists(), 'notes' => null],
            );
        });
    }

    public function remove(AppServer $app, AppAllocation $allocation): void
    {
        $this->assertOwned($app, $allocation);
        if ($allocation->is_primary) {
            throw new \DomainException(__('Nie można usunąć portu głównego — najpierw ustaw inny port jako główny.'));
        }
        $allocation->delete();
    }

    public function makePrimary(AppServer $app, AppAllocation $allocation): void
    {
        $this->assertOwned($app, $allocation);
        DB::transaction(function () use ($app, $allocation) {
            AppAllocation::query()->where('app_server_id', $app->id)->update(['is_primary' => false]);
            $allocation->update(['is_primary' => true]);
        });
    }

    private function assertOwned(AppServer $app, AppAllocation $allocation): void
    {
        if ($allocation->app_server_id !== $app->id) {
            throw new \DomainException(__('Ten port nie należy do aplikacji.'));
        }
    }

    public function release(AppServer $app): void
    {
        AppAllocation::query()->where('app_server_id', $app->id)->delete();
    }
}
