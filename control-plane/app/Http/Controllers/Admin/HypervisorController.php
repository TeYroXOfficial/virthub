<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Network\HypervisorGroupManager;
use App\Domain\Provisioning\HypervisorSelector;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HypervisorController extends Controller
{
    public function index(): JsonResponse
    {
        $hypervisors = Hypervisor::query()->withCount('servers')->orderBy('name')->get();

        return response()->json([
            'data' => $hypervisors->map(fn (Hypervisor $h) => $this->present($h)),
        ]);
    }

    /**
     * Rejestracja node'a. Sekrety pokazujemy wyłącznie w tej odpowiedzi —
     * w bazie leżą zaszyfrowane i nie ma endpointu, który je odda ponownie.
     * Zgubiony token oznacza rotację, nie odczyt.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'hostname' => ['required', 'string', 'max:253'],
            'agent_url' => ['required', 'url', 'max:255'],
            'cpu_cores_total' => ['required', 'integer', 'min:1'],
            'ram_mb_total' => ['required', 'integer', 'min:1024'],
            'disk_gb_total' => ['required', 'integer', 'min:10'],
            'bridge' => ['nullable', 'string', 'max:32'],
        ]);

        $agentToken = Str::random(64);
        $callbackSecret = Str::random(64);

        $hypervisor = Hypervisor::create([
            ...$validated,
            'bridge' => $validated['bridge'] ?? 'br0',
            'agent_token' => $agentToken,
            'callback_secret' => $callbackSecret,
            'status' => Hypervisor::STATUS_OFFLINE,
        ]);

        AuditLog::record('hypervisor.created', $hypervisor, ['name' => $hypervisor->name]);

        return response()->json([
            'data' => $this->present($hypervisor),
            'agent_env' => [
                'VH_AGENT_TOKEN' => $agentToken,
                'VH_CALLBACK_SECRET' => $callbackSecret,
                'VH_CONTROL_PLANE_URL' => config('app.url'),
                'VH_BRIDGE' => $hypervisor->bridge,
            ],
            'note' => 'Skopiuj wartości do /etc/virthub-agent/agent.env na hypervisorze. '
                .'Nie zobaczysz ich ponownie — zgubione trzeba wygenerować od nowa.',
        ], 201);
    }

    public function show(Hypervisor $hypervisor): JsonResponse
    {
        return response()->json(['data' => $this->present($hypervisor->loadCount('servers'))]);
    }

    public function update(Request $request, Hypervisor $hypervisor, HypervisorGroupManager $groups): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'agent_url' => ['sometimes', 'url', 'max:255'],
            'status' => ['sometimes', Rule::in([
                Hypervisor::STATUS_ONLINE,
                Hypervisor::STATUS_OFFLINE,
                Hypervisor::STATUS_MAINTENANCE,
            ])],
            'accepts_new_servers' => ['sometimes', 'boolean'],
            'cpu_cores_total' => ['sometimes', 'integer', 'min:1'],
            'ram_mb_total' => ['sometimes', 'integer', 'min:1024'],
            'disk_gb_total' => ['sometimes', 'integer', 'min:10'],
            'hypervisor_group_id' => ['sometimes', 'nullable', 'integer', 'exists:hypervisor_groups,id'],
        ]);

        if (array_key_exists('hypervisor_group_id', $validated)) {
            $groupId = $validated['hypervisor_group_id'];
            $groups->assign($hypervisor, $groupId ? HypervisorGroup::find($groupId) : null);
            unset($validated['hypervisor_group_id']);
        }

        $hypervisor->update($validated);
        AuditLog::record('hypervisor.updated', $hypervisor, $validated);

        return response()->json(['data' => $this->present($hypervisor->fresh())]);
    }

    public function destroy(Hypervisor $hypervisor): JsonResponse
    {
        if ($hypervisor->servers()->exists()) {
            return response()->json([
                'message' => 'Na tym hypervisorze są jeszcze maszyny. Przenieś je lub usuń, '
                    .'zanim skasujesz węzeł.',
            ], 409);
        }

        AuditLog::record('hypervisor.deleted', $hypervisor, ['name' => $hypervisor->name]);
        $hypervisor->delete();

        return response()->json(['message' => 'Hypervisor został usunięty.']);
    }

    /** Sprawdzenie łączności na żądanie — bez czekania na cykliczny heartbeat. */
    public function health(Hypervisor $hypervisor): JsonResponse
    {
        try {
            $health = (new AgentClient($hypervisor))->health();
        } catch (AgentException $e) {
            return response()->json([
                'reachable' => false,
                'message' => $e->getMessage(),
            ], 503);
        }

        $hypervisor->forceFill([
            'last_seen_at' => now(),
            'last_health' => $health,
            'status' => $hypervisor->status === Hypervisor::STATUS_MAINTENANCE
                ? Hypervisor::STATUS_MAINTENANCE
                : Hypervisor::STATUS_ONLINE,
        ])->save();

        return response()->json(['reachable' => true, 'health' => $health]);
    }

    /** Przelicza zajętość z maszyn w bazie — po awarii albo ręcznych zmianach. */
    public function recalculate(Hypervisor $hypervisor, HypervisorSelector $selector): JsonResponse
    {
        $selector->recalculate($hypervisor);
        AuditLog::record('hypervisor.recalculated', $hypervisor);

        return response()->json(['data' => $this->present($hypervisor->fresh())]);
    }

    public function rotateToken(Hypervisor $hypervisor): JsonResponse
    {
        $agentToken = Str::random(64);
        $callbackSecret = Str::random(64);

        $hypervisor->forceFill([
            'agent_token' => $agentToken,
            'callback_secret' => $callbackSecret,
        ])->save();

        AuditLog::record('hypervisor.token_rotated', $hypervisor);

        return response()->json([
            'agent_env' => [
                'VH_AGENT_TOKEN' => $agentToken,
                'VH_CALLBACK_SECRET' => $callbackSecret,
            ],
            'note' => 'Wgraj nowe wartości na hypervisor i zrestartuj agenta. '
                .'Do tego czasu panel nie może zlecać mu zadań.',
        ]);
    }

    private function present(Hypervisor $hypervisor): array
    {
        return [
            'id' => $hypervisor->id,
            'name' => $hypervisor->name,
            'hostname' => $hypervisor->hostname,
            'agent_url' => $hypervisor->agent_url,
            'status' => $hypervisor->status,
            'online' => $hypervisor->isOnline(),
            'accepts_new_servers' => $hypervisor->accepts_new_servers,
            'bridge' => $hypervisor->bridge,
            'hypervisor_group_id' => $hypervisor->hypervisor_group_id,
            'servers_count' => $hypervisor->servers_count ?? $hypervisor->servers()->count(),
            'capacity' => [
                'cpu_cores_total' => $hypervisor->cpu_cores_total,
                'cpu_cores_used' => $hypervisor->cpu_cores_used,
                'ram_mb_total' => $hypervisor->ram_mb_total,
                'ram_mb_used' => $hypervisor->ram_mb_used,
                'disk_gb_total' => $hypervisor->disk_gb_total,
                'disk_gb_used' => $hypervisor->disk_gb_used,
                'utilisation_percent' => $hypervisor->utilisationPercent(),
            ],
            'last_seen_at' => $hypervisor->last_seen_at,
            'last_health' => $hypervisor->last_health,
        ];
    }
}
