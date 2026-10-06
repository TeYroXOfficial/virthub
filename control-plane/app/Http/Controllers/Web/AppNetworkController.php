<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Apps\AppPorts;
use App\Domain\Apps\AppProvisioner;
use App\Http\Controllers\Controller;
use App\Models\AppAllocation;
use App\Models\AppServer;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Zakładka „Sieć” aplikacji: porty (dodawanie, usuwanie, główny, notatki). */
class AppNetworkController extends Controller
{
    public function __construct(
        private readonly AppPorts $ports,
        private readonly AppProvisioner $apps,
    ) {}

    public function index(AppServer $app): View
    {
        $this->authorize('view', $app);
        $app->load(['egg', 'plan', 'allocations', 'hypervisor', 'user']);

        return view('panel.apps.network', [
            'app' => $app,
            'tab' => 'network',
            'limit' => $this->ports->limit($app),
            'free' => $app->hypervisor ? $this->ports->freeCount($app->hypervisor) : 0,
        ]);
    }

    public function store(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $staff = $request->user()->can('manage', $app);
        $data = $request->validate(['port' => ['nullable', 'integer', 'between:1,65535']]);

        try {
            $allocation = $this->ports->add($app, $staff ? ($data['port'] ?? null) : null, $staff);
        } catch (\DomainException $e) {
            return back()->withErrors(['port' => $e->getMessage()]);
        }
        AuditLog::record('app.port_added', $app, ['port' => $allocation->port], $request->user());

        return $this->synced($app, __('Dodano port :port.', ['port' => $allocation->port]));
    }

    public function primary(Request $request, AppServer $app, AppAllocation $allocation): RedirectResponse
    {
        $this->authorize('operate', $app);
        try {
            $this->ports->makePrimary($app, $allocation);
        } catch (\DomainException $e) {
            return back()->withErrors(['port' => $e->getMessage()]);
        }
        AuditLog::record('app.port_primary', $app, ['port' => $allocation->port], $request->user());

        return $this->synced($app, __('Port :port jest teraz głównym portem aplikacji.', ['port' => $allocation->port]));
    }

    public function note(Request $request, AppServer $app, AppAllocation $allocation): RedirectResponse
    {
        $this->authorize('operate', $app);
        abort_unless($allocation->app_server_id === $app->id, 404);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:60']]);
        $allocation->update(['notes' => $data['notes'] ?: null]);

        return back()->with('status', __('Notatka zapisana.'));
    }

    public function destroy(Request $request, AppServer $app, AppAllocation $allocation): RedirectResponse
    {
        $this->authorize('operate', $app);
        try {
            $this->ports->remove($app, $allocation);
        } catch (\DomainException $e) {
            return back()->withErrors(['port' => $e->getMessage()]);
        }
        AuditLog::record('app.port_removed', $app, ['port' => $allocation->port], $request->user());

        return $this->synced($app, __('Usunięto port :port.', ['port' => $allocation->port]));
    }

    /** Personel: limit portów tej aplikacji (puste = limit planu). */
    public function limit(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('manage', $app);
        $data = $request->validate(['port_limit' => ['nullable', 'integer', 'between:1,100']]);
        $app->forceFill(['port_limit' => $data['port_limit'] ?? null])->save();
        AuditLog::record('app.port_limit', $app, ['limit' => $app->port_limit], $request->user());

        return back()->with('status', __('Limit portów zapisany.'));
    }

    /** Nowe porty na węźle; kontener dostaje je przy następnym starcie. */
    private function synced(AppServer $app, string $message): RedirectResponse
    {
        try {
            $restart = $this->apps->syncSpec($app);
        } catch (AgentException) {
            return back()->with('status', $message.' '.__('Węzeł nie odpowiada — zmiana zostanie wysłana przy następnym starcie aplikacji.'));
        }

        return back()->with('status', $restart ? $message.' '.__('Zrestartuj aplikację, żeby kontener dostał nowe porty.') : $message);
    }
}
