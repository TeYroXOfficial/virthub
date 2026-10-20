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

    /**
     * Zapis całego bloku naraz (siatka portów): pusty albo równy numerowi
     * zewnętrznemu — port wraca na 1:1, inny numer — przekierowanie.
     */
    public function update(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('firewall', $server);
        $request->validate([
            'ports' => ['required', 'array'],
            'ports.*' => ['array'],
            'ports.*.*' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ], array_fill_keys(['ports.*.*.integer', 'ports.*.*.min', 'ports.*.*.max'], __('Port w maszynie musi być liczbą od 1 do 65535.')));

        $changed = 0;
        foreach ((array) $request->input('ports') as $ipId => $ports) {
            $ip = IpAddress::query()->with('pool')->where('server_id', $server->id)->findOrFail((int) $ipId);
            $custom = NatPortForward::query()->where('ip_address_id', $ip->id)->get()->keyBy('external_port');
            $editable = collect(PortForwarding::mapping($ip, $server))->where('kind', '!=', PortForwarding::KIND_FIXED)->pluck('external')->all();
            foreach ((array) $ports as $external => $internal) {
                $external = (int) $external;
                if (! in_array($external, $editable, true)) {
                    continue; // port spoza bloku albo stały (SSH/RDP)
                }
                $internal = $internal === null || $internal === '' ? null : (int) $internal;
                $current = $custom->get($external);
                if ($internal === null || $internal === $external) {
                    if ($current !== null) {
                        $this->forwarding->remove($current, $request->user());
                        $changed++;
                    }
                } elseif ($current?->internal_port !== $internal) {
                    $this->forwarding->set($server, $ip, $external, $internal, $current?->label, $request->user());
                    $changed++;
                }
            }
        }
        if ($changed === 0) {
            return $this->done($server, __('Bez zmian.'));
        }
        $this->provisioner->syncNetwork($server, $request->user());

        return $this->done($server, trans_choice('Zapisano :count przekierowanie.|Zapisano :count przekierowania.|Zapisano :count przekierowań.', $changed, ['count' => $changed]));
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
