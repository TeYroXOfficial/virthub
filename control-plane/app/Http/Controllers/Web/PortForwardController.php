<?php

namespace App\Http\Controllers\Web;

use App\Domain\Network\PortForwarding;
use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\NatPortForward;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Przekierowania portów NAT: port węzła z bloku maszyny → usługa w maszynie. */
class PortForwardController extends Controller
{
    public function __construct(
        private readonly PortForwarding $forwarding,
        private readonly ServerProvisioner $provisioner,
    ) {}

    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('firewall', $server);

        $validated = $request->validate([
            'ip_address_id' => ['required', 'integer'],
            'external_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'internal_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'label' => ['nullable', 'string', 'max:40'],
        ]);

        $ip = IpAddress::query()->with('pool')->where('server_id', $server->id)->findOrFail($validated['ip_address_id']);

        $this->forwarding->set(
            $server, $ip,
            (int) $validated['external_port'], (int) $validated['internal_port'],
            $validated['label'] ?? null, $request->user(),
        );
        $this->provisioner->syncNetwork($server, $request->user());

        return $this->done($server, __('Port :external prowadzi teraz na port :internal w maszynie.', [
            'external' => $validated['external_port'], 'internal' => $validated['internal_port'],
        ]));
    }

    public function destroy(Request $request, Server $server, NatPortForward $forward): RedirectResponse
    {
        $this->authorize('firewall', $server);
        abort_unless($forward->server_id === $server->id, 404);

        $external = $forward->external_port;
        $this->forwarding->remove($forward, $request->user());
        $this->provisioner->syncNetwork($server, $request->user());

        return $this->done($server, __('Port :external wraca na ten sam numer w maszynie (1:1).', ['external' => $external]));
    }

    private function done(Server $server, string $message): RedirectResponse
    {
        return redirect()->to(route('panel.servers.show', $server).'#ports')->with('status', $message);
    }
}
