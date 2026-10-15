<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Dashboard;
use App\Enums\ServerState;
use App\Http\Controllers\Controller;
use App\Models\Hypervisor;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Stan panelu dla monitoringu (Uptime Kuma, Zabbix, Grafana): wersja, baza,
 * kolejka, węzły i maszyny. 200 = wszystko działa, 503 = coś wymaga uwagi.
 */
class StatusController extends Controller
{
    public function __invoke(Dashboard $dashboard): JsonResponse
    {
        $nodes = Hypervisor::query()->get();
        $system = $dashboard->system($nodes);

        $database = rescue(fn () => DB::select('select 1') !== [], false, false);
        $queueSize = rescue(fn () => Queue::size(), null, false);
        $offline = $nodes->filter(fn (Hypervisor $n) => $n->enrolled_at !== null && ! $n->isOnline() && $n->status !== Hypervisor::STATUS_MAINTENANCE);
        $states = Server::query()->selectRaw('state, count(*) as c')->groupBy('state')->pluck('c', 'state');
        $ok = $database && $offline->isEmpty();

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'version' => $system['version'],
            'up_to_date' => $system['up_to_date'],
            'time' => now()->toIso8601String(),
            'checks' => [
                'database' => $database,
                'mail' => $system['mail'],
                'queue_size' => $queueSize,
                'failed_jobs_24h' => $system['failed_jobs'],
            ],
            'nodes' => [
                'total' => $system['nodes_total'],
                'online' => $system['nodes_online'],
                'offline' => $offline->pluck('name')->values(),
            ],
            'servers' => [
                'total' => (int) $states->sum(),
                'running' => (int) ($states[ServerState::Running->value] ?? 0),
                'error' => (int) ($states[ServerState::Error->value] ?? 0),
                'by_state' => $states,
            ],
            'customers' => $system['customers'],
        ], $ok ? 200 : 503);
    }
}
