<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Http\Controllers\Controller;
use App\Models\Hypervisor;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Infrastruktura → Bezpieczeństwo: ochrona węzłów przed ucieczkami z maszyn
 * do hosta (Januscape, Zapscape, ITScape i inne), stan jądra i podatności CPU.
 */
class NodeSecurityController extends Controller
{
    public function index(): View
    {
        $nodes = Hypervisor::query()->whereNotNull('enrolled_at')->orderBy('name')->get();

        return view('panel.admin.security', [
            'nodes' => $nodes,
            'summary' => [
                'critical' => $nodes->filter(fn ($n) => ($n->securityReport()['overall'] ?? null) === 'critical')->count(),
                'warning' => $nodes->filter(fn ($n) => ($n->securityReport()['overall'] ?? null) === 'warning')->count(),
                'ok' => $nodes->filter(fn ($n) => ($n->securityReport()['overall'] ?? null) === 'ok')->count(),
                'unknown' => $nodes->filter(fn ($n) => $n->securityReport() === null)->count(),
            ],
        ]);
    }

    /**
     * Zagnieżdżona wirtualizacja: „auto” — węzeł włącza ją sam tylko na jądrze z
     * poprawkami znanych ucieczek; „allow” — administrator włącza ją mimo braku poprawek.
     */
    public function nested(\Illuminate\Http\Request $request, Hypervisor $hypervisor): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $policy = $request->validate(['policy' => ['required', 'in:auto,allow']])['policy'];
        if ($policy === 'allow' && ! $request->boolean('confirm')) {
            return back()->withErrors(['policy' => __('Potwierdź, że rozumiesz ryzyko włączenia zagnieżdżania na jądrze bez poprawek.')]);
        }

        try {
            (new AgentClient($hypervisor))->setNestedPolicy($policy);
        } catch (AgentException $e) {
            return back()->withErrors(['policy' => __('Węzeł :name nie przyjął zmiany: :error', ['name' => $hypervisor->name, 'error' => $e->getMessage()])]);
        }

        $health = $hypervisor->last_health ?? [];
        if (isset($health['host_security'])) {
            $health['host_security']['nested_policy'] = $policy;
            $hypervisor->forceFill(['last_health' => $health])->save();
        }
        \App\Models\AuditLog::record('hypervisor.nested_policy', $hypervisor, ['policy' => $policy], $request->user());

        return redirect()->to(route('panel.admin.security').'#node-'.$hypervisor->id)->with('status', $policy === 'allow'
            ? __('Zagnieżdżona wirtualizacja na węźle :name zostanie włączona w ciągu kilku sekund (przy działających maszynach — po restarcie węzła). Maszyny dostaną ją po wyłączeniu i włączeniu.', ['name' => $hypervisor->name])
            : __('Węzeł :name wraca do trybu automatycznego: zagnieżdżanie tylko na jądrze z poprawkami.', ['name' => $hypervisor->name]));
    }

    /** Świeży audyt z węzła — po aktualizacji albo restarcie nie trzeba czekać na raport zdrowia. */
    public function check(Hypervisor $hypervisor): RedirectResponse
    {
        try {
            $report = (new AgentClient($hypervisor))->securityAudit();
        } catch (AgentException $e) {
            return back()->withErrors(['security' => __('Nie udało się sprawdzić węzła :name: :error', ['name' => $hypervisor->name, 'error' => $e->getMessage()])]);
        }

        $hypervisor->forceFill(['last_health' => ['host_security' => $report] + ($hypervisor->last_health ?? [])])->save();

        return redirect()->to(route('panel.admin.security').'#node-'.$hypervisor->id)
            ->with('status', __('Sprawdzono węzeł :name.', ['name' => $hypervisor->name]));
    }
}
