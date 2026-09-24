<?php

namespace App\Http\Controllers\Api;

use App\Domain\Network\Firewall;
use App\Http\Controllers\Controller;
use App\Models\FirewallRule;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reguły zapory są przechowywane w bazie i z niej generowane na hypervisorze.
 * Panel jest źródłem prawdy — ręczna zmiana nftables na hoście zostanie
 * nadpisana przy następnej synchronizacji, i tak ma być.
 */
class FirewallController extends Controller
{
    public function __construct(private readonly Firewall $firewall) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        return response()->json([
            'policy' => [
                ...Firewall::policyPayload($server),
                'locked' => (bool) $server->firewall_locked,
                'editable' => $this->firewall->canManage($request->user(), $server),
            ],
            'data' => $server->firewallRules->map(fn (FirewallRule $rule) => [
                'id' => $rule->id,
                'managed_by' => $rule->managed_by,
                'enabled' => $rule->enabled,
                'action' => $rule->action,
                'direction' => $rule->direction,
                'protocol' => $rule->protocol,
                'port_from' => $rule->port_from,
                'port_to' => $rule->port_to,
                'source' => $rule->source,
                'comment' => $rule->comment,
                'description' => $rule->describe(),
            ])->values(),
            'note' => 'Ruch wychodzący z adresów spoza puli maszyny jest blokowany zawsze '
                .'i nie da się tego wyłączyć regułą. Reguły administratora są sprawdzane przed regułami klienta.',
        ]);
    }

    public function policy(Request $request, Server $server): JsonResponse
    {
        $this->authorize('operate', $server);

        $this->firewall->updatePolicy($server, $request->user(), $request->validate(Firewall::policyRules()));

        return response()->json([
            'policy' => Firewall::policyPayload($server->fresh()),
            'message' => 'Ustawienia zapory są wdrażane na hypervisorze.',
        ]);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize('operate', $server);

        $rule = $this->firewall->addRule($server, $request->user(), $request->validate(Firewall::ruleRules()));

        return response()->json([
            'id' => $rule->id,
            'managed_by' => $rule->managed_by,
            'description' => $rule->describe(),
            'message' => 'Reguła została dodana i jest wdrażana na hypervisorze.',
        ], 201);
    }

    public function destroy(Request $request, Server $server, FirewallRule $rule): JsonResponse
    {
        $this->authorize('operate', $server);

        $this->firewall->deleteRule($server, $request->user(), $rule);

        return response()->json(['message' => 'Reguła została usunięta.']);
    }
}
