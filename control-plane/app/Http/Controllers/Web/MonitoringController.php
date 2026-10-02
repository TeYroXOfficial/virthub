<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Http\Controllers\Controller;
use App\Models\AppServer;
use App\Models\Hypervisor;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Infrastruktura → Monitorowanie: obciążenie hosta na żywo (procesor, steal,
 * pamięć, dyski i I/O, temperatury, sieć) oraz zużycie zasobów przez każdą
 * maszynę KVM, kontener LXC i aplikację — prosto z systemu węzła.
 */
class MonitoringController extends Controller
{
    public function index(Request $request): View
    {
        $nodes = Hypervisor::query()->whereNotNull('enrolled_at')->orderBy('name')->get();
        $node = $nodes->firstWhere('id', $request->integer('node'))
            ?? $nodes->first(fn (Hypervisor $n) => $n->isOnline())
            ?? $nodes->first();

        return view('panel.admin.monitoring', compact('nodes', 'node'));
    }

    public function data(Hypervisor $hypervisor): JsonResponse
    {
        if ($hypervisor->enrolled_at === null) {
            return response()->json(['error' => __('Węzeł nie jest jeszcze zainstalowany.')], 409);
        }

        try {
            $report = (new AgentClient($hypervisor))->monitor();
        } catch (AgentException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        // Identyfikatory z węzła → nazwy i linki z panelu.
        $serverIds = collect($report['services'] ?? [])->pluck('server_id')
            ->merge(collect($report['processes'] ?? [])->pluck('owner.server_id'))->filter()->unique();
        $appUuids = collect($report['services'] ?? [])->pluck('app_uuid')
            ->merge(collect($report['processes'] ?? [])->pluck('owner.app_uuid'))->filter()->unique();
        $servers = Server::query()->with('user:id,email')->whereIn('id', $serverIds)->get()->keyBy('id');
        $apps = AppServer::query()->with('user:id,email')->whereIn('uuid', $appUuids)->get()->keyBy('uuid');

        $describe = function (?array $item) use ($servers, $apps): ?array {
            if ($item === null) {
                return null;
            }
            $server = isset($item['server_id']) ? $servers->get($item['server_id']) : null;
            $app = isset($item['app_uuid']) ? $apps->get($item['app_uuid']) : null;
            $model = $server ?? $app;

            return $item + [
                'name' => $server?->hostname ?? $app?->name ?? ($item['ref'] ?? null),
                'customer' => $model?->user?->email,
                'url' => $server ? route('panel.servers.show', $server) : ($app ? route('panel.apps.show', $app) : null),
                'known' => $model !== null,
            ];
        };

        $report['services'] = array_map($describe, $report['services'] ?? []);
        $report['processes'] = array_map(fn ($p) => ['owner' => $describe($p['owner'] ?? null)] + $p, $report['processes'] ?? []);
        $report['fetched_at'] = now()->toIso8601String();

        return response()->json($report);
    }
}
