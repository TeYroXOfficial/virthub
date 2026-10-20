<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Security\VulnerabilityFeed;
use App\Http\Controllers\Controller;
use App\Jobs\ScanVulnerabilitiesJob;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Infrastruktura → Bezpieczeństwo: ochrona węzłów przed ucieczkami z maszyn
 * do hosta (Januscape, Zapscape, ITScape i inne), stan jądra i podatności CPU.
 */
class NodeSecurityController extends Controller
{
    public function index(VulnerabilityFeed $feed): View
    {
        $nodes = Hypervisor::query()->whereNotNull('enrolled_at')->orderBy('name')->get();
        $overall = $nodes->mapWithKeys(fn ($n) => [$n->id => $feed->overall($n)]);
        $packages = $nodes->mapWithKeys(fn ($n) => [$n->id => $feed->forNode($n)]);
        $new = $packages->flatMap(fn ($r) => collect($r['packages'])->flatMap(fn ($p) => $p['relevant']))
            ->filter(fn ($v) => $v['vulnerability']->isNew())
            ->unique(fn ($v) => $v['vulnerability']->id)->count();

        return view('panel.admin.security', [
            'nodes' => $nodes,
            'overall' => $overall,
            'packages' => $packages,
            'summary' => [
                'critical' => $overall->filter(fn ($o) => $o === 'critical')->count(),
                'warning' => $overall->filter(fn ($o) => $o === 'warning')->count(),
                'ok' => $overall->filter(fn ($o) => $o === 'ok')->count(),
                'unknown' => $overall->filter(fn ($o) => $o === null)->count(),
                'new' => $new,
            ],
        ]);
    }

    /**
     * Zagnieżdżona wirtualizacja: „auto” — węzeł włącza ją sam tylko na jądrze z
     * poprawkami znanych ucieczek; „allow” — administrator włącza ją mimo braku poprawek.
     */
    public function nested(Request $request, Hypervisor $hypervisor): RedirectResponse
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
        AuditLog::record('hypervisor.nested_policy', $hypervisor, ['policy' => $policy], $request->user());

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
        ScanVulnerabilitiesJob::dispatch($hypervisor->id);

        return redirect()->to(route('panel.admin.security').'#node-'.$hypervisor->id)
            ->with('status', __('Sprawdzono węzeł :name.', ['name' => $hypervisor->name]));
    }
}
