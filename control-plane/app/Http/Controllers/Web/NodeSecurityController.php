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
