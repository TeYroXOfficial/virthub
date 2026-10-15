<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\GuestSettings;
use App\Domain\Provisioning\RescueMode;
use App\Http\Controllers\Controller;
use App\Models\IpAddress;
use App\Models\Server;
use App\Rules\SshPublicKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Tryb ratunkowy, dysk cloud-init, nazwa hosta i wybór adresu IPv6 — na stronie maszyny. */
class GuestSettingsController extends Controller
{
    public function startRescue(Request $request, Server $server, RescueMode $rescue): RedirectResponse
    {
        $this->authorize('rebuild', $server);
        $keys = array_values(array_filter([trim((string) $request->input('ssh_key'))]));
        $request->validate(['ssh_key' => ['nullable', 'string', 'max:1000', new SshPublicKey]]);

        return $this->guard($server, '#rescue', fn () => $rescue->start($server, $request->user(), $keys),
            __('Maszyna uruchamia się w trybie ratunkowym. Dane logowania pojawią się tutaj za chwilę.'));
    }

    public function stopRescue(Request $request, Server $server, RescueMode $rescue): RedirectResponse
    {
        $this->authorize('rebuild', $server);

        return $this->guard($server, '#rescue', fn () => $rescue->stop($server, $request->user()), __('Maszyna wraca do swojego systemu.'));
    }

    public function cloudInit(Request $request, Server $server, GuestSettings $settings): RedirectResponse
    {
        $this->authorize('iso', $server);
        $enabled = $request->boolean('enabled');

        return $this->guard($server, '#iso', fn () => $settings->cloudInit($server, $enabled, $request->user()),
            $enabled ? __('Dysk konfiguracji zostanie podłączony przy następnym uruchomieniu.') : __('Dysk konfiguracji zostanie odłączony przy następnym uruchomieniu.'));
    }

    public function hostname(Request $request, Server $server, GuestSettings $settings): RedirectResponse
    {
        $this->authorize('power', $server);
        $data = $request->validate(['hostname' => ['required', 'string', 'max:253']]);

        return $this->guard($server, '#hostname', fn () => $settings->hostname($server, $data['hostname'], $request->user()), __('Zmieniono nazwę hosta.'));
    }

    public function ipv6(Request $request, Server $server, IpAddress $address, GuestSettings $settings): RedirectResponse
    {
        $this->authorize('firewall', $server);
        abort_unless($address->server_id === $server->id, 404);
        $data = $request->validate(['address' => ['required', 'ip']]);

        return $this->guard($server, '#network', fn () => $settings->changeIpv6($server, $address, $data['address'], $request->user()),
            __('Adres IPv6 zmieniony. Ustaw nowy adres w systemie albo zreinstaluj system, żeby cloud-init zrobił to sam.'));
    }

    private function guard(Server $server, string $anchor, \Closure $action, string $message): RedirectResponse
    {
        $to = redirect()->to(route('panel.servers.show', $server).$anchor);
        try {
            $action();

            return $to->with('status', $message);
        } catch (\DomainException $e) {
            return $to->withErrors(['guest' => $e->getMessage()]);
        }
    }
}
