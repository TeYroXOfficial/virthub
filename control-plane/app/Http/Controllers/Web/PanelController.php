<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\ServerProvisioner;
use App\Enums\ServerState;
use App\Enums\Virtualization;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderServerRequest;
use App\Models\Hypervisor;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\VpsPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PanelController extends Controller
{
    public function __construct(private readonly ServerProvisioner $provisioner) {}

    public function dashboard(Request $request): View
    {
        $user = $request->user();

        $servers = Server::query()
            ->ownedBy($user)
            ->with(['package', 'template', 'ipAddresses'])
            ->latest()
            ->get();

        return view('panel.dashboard', [
            'servers' => $servers,
            'running' => $servers->where('state', ServerState::Running)->count(),
            'building' => $servers->filter(fn (Server $s) => $s->state->isTransitioning())->count(),
            // Podsumowanie floty ma sens tylko dla personelu — klientowi
            // pokazujemy jego własne maszyny, nie stan naszej infrastruktury.
            'fleet' => $user->isStaff()
                ? Hypervisor::query()->orderBy('name')->get()
                : null,
        ]);
    }

    public function showServer(Request $request, Server $server): View
    {
        $this->authorize('view', $server);

        $server->load(['package', 'template', 'ipAddresses.pool', 'firewallRules', 'backups', 'hypervisor', 'iso']);

        return view('panel.servers.show', [
            'server' => $server,
            'recentJobs' => $server->jobs()->limit(10)->get(),
            'packages' => VpsPackage::query()->active()->orderBy('vcpu')->get()
                ->filter(fn (VpsPackage $p) => $request->user()->mayOrderPackage($p))
                ->values(),
            // Reinstalacja: tylko systemy tego samego typu, z automatyczną instalacją.
            'templates' => OsTemplate::query()->active()
                ->where('virtualization', $server->virtualization->value)
                ->where('cloud_init_support', true)
                ->orderBy('family')->orderByDesc('version')
                ->get(),
            'isos' => $server->isContainer()
                ? collect()
                : app(\App\Domain\Provisioning\IsoLibrary::class)->availableFor($server->hypervisor_id, $request->user()->isStaff()),
            // Hasło startowe pokazujemy raz — po wyświetleniu znika z bazy.
            'rootPassword' => $server->consumeRootPassword(),
        ]);
    }

    public function createServer(Request $request): View
    {
        $this->authorize('create', Server::class);

        // Pokazujemy tylko systemy, które da się faktycznie postawić: szablon
        // kontenera bez żadnego węzła kontenerów skończyłby się błędem
        // „brak zasobów" dopiero po złożeniu zamówienia.
        $availableTypes = Hypervisor::query()
            ->available()
            ->pluck('virtualization')
            ->map(fn ($type) => $type instanceof Virtualization ? $type->value : $type)
            ->unique()
            ->all();

        $templates = OsTemplate::query()
            ->active()
            ->where('cloud_init_support', true)
            ->whereIn('virtualization', $availableTypes)
            ->orderBy('family')
            ->orderByDesc('version')
            ->get()
            ->groupBy(fn (OsTemplate $t) => $t->virtualization->value);

        return view('panel.servers.create', [
            'packages' => VpsPackage::query()->active()->orderBy('vcpu')->get()
                ->filter(fn (VpsPackage $p) => $request->user()->mayOrderPackage($p))
                ->values(),
            'templateGroups' => $templates,
        ]);
    }

    public function storeServer(OrderServerRequest $request): RedirectResponse
    {
        $this->authorize('create', Server::class);
        // Limit maszyn i dozwolone pakiety sprawdza OrderServerRequest.

        $server = $this->provisioner->order(
            user: $request->user(),
            package: $request->package(),
            template: $request->template(),
            hostname: $request->string('hostname')->lower()->value(),
            sshKeys: $request->sshKeys(),
            label: $request->input('label'),
        );

        return redirect()
            ->route('panel.servers.show', $server)
            ->with('status', 'Maszyna jest tworzona. Zwykle trwa to około minuty.');
    }
}
