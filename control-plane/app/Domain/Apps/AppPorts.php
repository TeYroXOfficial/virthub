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

    public function release(AppServer $app): void
    {
        AppAllocation::query()->where('app_server_id', $app->id)->delete();
    }
}
