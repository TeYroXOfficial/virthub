<?php

namespace App\Http\Controllers\Api;

use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Models\FirewallRule;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reguły firewalla są przechowywane w bazie i z niej generowane na hypervisorze.
 * Panel jest źródłem prawdy — ręczna zmiana nftables na hoście zostanie
 * nadpisana przy następnej synchronizacji, i tak ma być.
 */
class FirewallController extends Controller
{
    public function __construct(private readonly ServerProvisioner $provisioner) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        return response()->json([
            'data' => $server->firewallRules->map(fn (FirewallRule $rule) => [
                'id' => $rule->id,
                'action' => $rule->action,
                'direction' => $rule->direction,
                'protocol' => $rule->protocol,
                'port_from' => $rule->port_from,
                'port_to' => $rule->port_to,
                'source' => $rule->source,
                'comment' => $rule->comment,
                'description' => $rule->describe(),
            ]),
            'note' => 'Ruch wychodzący z adresów spoza puli maszyny jest blokowany zawsze '
                .'i nie da się tego wyłączyć regułą.',
        ]);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize('operate', $server);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['accept', 'drop'])],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'protocol' => ['required', Rule::in(['tcp', 'udp', 'icmp', 'any'])],
            'port_from' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'port_to' => ['nullable', 'integer', 'min:1', 'max:65535', 'gte:port_from'],
            'source' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:120'],
        ]);

        if (in_array($validated['protocol'], ['tcp', 'udp'], true) && empty($validated['port_from'])) {
            return response()->json([
                'message' => 'Reguła dla TCP/UDP wymaga podania portu lub zakresu portów.',
            ], 422);
        }

        $rule = $server->firewallRules()->create([
            ...$validated,
            'position' => ($server->firewallRules()->max('position') ?? 0) + 1,
        ]);

        $this->provisioner->syncNetwork($server, $request->user());

        return response()->json([
            'id' => $rule->id,
            'description' => $rule->describe(),
            'message' => 'Reguła została dodana i jest wdrażana na hypervisorze.',
        ], 201);
    }

    public function destroy(Request $request, Server $server, FirewallRule $rule): JsonResponse
    {
        $this->authorize('operate', $server);

        if ($rule->server_id !== $server->id) {
            abort(404);
        }

        $rule->delete();
        $this->provisioner->syncNetwork($server, $request->user());

        return response()->json(['message' => 'Reguła została usunięta.']);
    }
}
