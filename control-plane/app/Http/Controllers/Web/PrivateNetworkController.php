<?php

namespace App\Http\Controllers\Web;

use App\Domain\Network\PrivateNetworks;
use App\Http\Controllers\Controller;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\ServerNic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Sieci prywatne na stronie maszyny: podłączanie kart, nowe sieci, usuwanie pustych. */
class PrivateNetworkController extends Controller
{
    public function __construct(private readonly PrivateNetworks $networks) {}

    public function attach(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('firewall', $server);
        $data = $request->validate([
            'network_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'required_without:network_id', 'string', 'max:64'],
            'cidr' => ['nullable', 'string', 'max:18'],
            'address' => ['nullable', 'ip'],
        ]);

        return $this->guard($server, function () use ($server, $data, $request) {
            $network = ! empty($data['network_id'])
                ? PrivateNetwork::query()->where('user_id', $server->user_id)->findOrFail($data['network_id'])
                : null;
            if ($network === null) {
                $this->networks->assertAttachableToNew($server);
                $network = $this->networks->create($server->user, $server->hypervisor, $data['name'], $data['cidr'] ?? null, $request->user());
            }
            $this->networks->attach($server, $network, $data['address'] ?? null, $request->user());

            return __('Karta w sieci :name zostanie podłączona do maszyny.', ['name' => $network->name]);
        });
    }

    public function detach(Request $request, Server $server, ServerNic $nic): RedirectResponse
    {
        $this->authorize('firewall', $server);
        abort_unless($nic->server_id === $server->id, 404);

        return $this->guard($server, function () use ($nic, $request) {
            $this->networks->detach($nic, $request->user());

            return __('Karta zostanie odłączona.');
        });
    }

    public function destroy(Request $request, Server $server, PrivateNetwork $network): RedirectResponse
    {
        $this->authorize('firewall', $server);
        abort_unless($network->user_id === $server->user_id, 404);

        return $this->guard($server, function () use ($network, $request) {
            $this->networks->delete($network, $request->user());

            return __('Sieć :name została usunięta.', ['name' => $network->name]);
        });
    }

    private function guard(Server $server, \Closure $action): RedirectResponse
    {
        $to = redirect()->to(route('panel.servers.show', $server).'#private-networks');
        try {
            return $to->with('status', $action());
        } catch (\DomainException $e) {
            return $to->withErrors(['private_network' => $e->getMessage()])->withInput();
        }
    }
}
