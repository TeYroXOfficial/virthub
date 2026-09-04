<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\ServerProvisioner;
use App\Enums\ServerState;
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

        $server->load(['package', 'template', 'ipAddresses.pool', 'firewallRules', 'backups', 'hypervisor']);

        return view('panel.servers.show', [
            'server' => $server,
            'recentJobs' => $server->jobs()->limit(10)->get(),
            'packages' => VpsPackage::query()->active()->orderBy('vcpu')->get(),
            'templates' => OsTemplate::query()->active()->get(),
            // Hasło startowe pokazujemy raz — po wyświetleniu znika z bazy.
            'rootPassword' => $server->consumeRootPassword(),
        ]);
    }

    public function createServer(Request $request): View
    {
        $this->authorize('create', Server::class);

        return view('panel.servers.create', [
            'packages' => VpsPackage::query()->active()->orderBy('vcpu')->get(),
            'templates' => OsTemplate::query()->active()->orderBy('family')->get(),
        ]);
    }

    public function storeServer(OrderServerRequest $request): RedirectResponse
    {
        $this->authorize('create', Server::class);

        $limit = config('virthub.limits.servers_per_customer');
        $owned = Server::where('user_id', $request->user()->id)->count();

        if (! $request->user()->isStaff() && $owned >= $limit) {
            return back()->withErrors([
                'package' => "Osiągnięto limit {$limit} maszyn na koncie. "
                    .'Napisz do nas, jeśli potrzebujesz go zwiększyć.',
            ])->withInput();
        }

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
