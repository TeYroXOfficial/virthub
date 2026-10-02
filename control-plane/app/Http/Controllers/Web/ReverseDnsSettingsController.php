<?php

namespace App\Http\Controllers\Web;

use App\Domain\Network\ReverseDns;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Sieć → rDNS (PowerDNS): połączenie z API PowerDNS, w którym panel zapisuje rekordy PTR. */
class ReverseDnsSettingsController extends Controller
{
    public function show(): View
    {
        return view('panel.admin.network.dns', ['dns' => ReverseDns::settings(), 'configured' => ReverseDns::configured()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pdns_url' => ['nullable', 'url:http,https', 'max:255'],
            'pdns_key' => ['nullable', 'string', 'max:255'],
            'pdns_server' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'ttl' => ['required', 'integer', 'between:60,604800'],
        ]);

        $values = [
            'dns.pdns_url' => $data['pdns_url'] ?? null,
            'dns.pdns_server' => $data['pdns_server'],
            'dns.ttl' => (string) $data['ttl'],
            'dns.require_forward' => $request->boolean('require_forward') ? '1' : '0',
        ];
        // Puste pole klucza = bez zmian; wyczyszczenie adresu wyłącza integrację razem z kluczem.
        if (filled($data['pdns_key'] ?? null)) {
            $values['dns.pdns_key'] = Crypt::encryptString($data['pdns_key']);
        } elseif (empty($data['pdns_url'])) {
            $values['dns.pdns_key'] = null;
        }
        Setting::put($values);
        AuditLog::record('settings.dns', null, ['url' => $values['dns.pdns_url'], 'server' => $values['dns.pdns_server']], $request->user());

        return back()->with('status', __('Zapisano ustawienia rDNS.'));
    }

    public function test(ReverseDns $dns): RedirectResponse
    {
        if (! ReverseDns::configured()) {
            return back()->withErrors(['rdns' => __('Najpierw podaj adres API i klucz PowerDNS.')]);
        }
        try {
            $zones = $dns->reverseZones();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withErrors(['rdns' => __('Brak połączenia z PowerDNS: :error', ['error' => $e->getMessage()])]);
        }

        return back()->with('status', $zones
            ? __('Połączenie działa. Strefy odwrotne: :zones', ['zones' => implode(', ', array_slice($zones, 0, 10)).(count($zones) > 10 ? ' …' : '')])
            : __('Połączenie działa, ale PowerDNS nie ma żadnej strefy odwrotnej (in-addr.arpa / ip6.arpa) — dodaj ją, żeby rDNS trafiał do DNS.'));
    }
}
