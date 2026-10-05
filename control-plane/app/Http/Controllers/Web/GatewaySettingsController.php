<?php

namespace App\Http\Controllers\Web;

use App\Domain\Billing\Gateways\GatewayException;
use App\Domain\Billing\Gateways\GatewayRegistry;
use App\Domain\Billing\Gateways\GatewaySettings;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Administracja → Billing → Bramki płatności: Stripe i PayPal. */
class GatewaySettingsController extends Controller
{
    public function show(): View
    {
        return view('panel.admin.billing.gateways', [
            'stripe' => [
                'enabled' => GatewaySettings::enabled('stripe'),
                'has_secret' => GatewaySettings::has('stripe.secret_key'),
                'has_webhook' => GatewaySettings::has('stripe.webhook_secret'),
                'webhook_url' => route('billing.webhook', 'stripe'),
            ],
            'paypal' => [
                'enabled' => GatewaySettings::enabled('paypal'),
                'mode' => GatewaySettings::get('paypal.mode', 'sandbox'),
                'client_id' => GatewaySettings::get('paypal.client_id'),
                'has_secret' => GatewaySettings::has('paypal.client_secret'),
                'webhook_id' => GatewaySettings::get('paypal.webhook_id'),
                'webhook_url' => route('billing.webhook', 'paypal'),
            ],
        ]);
    }

    public function update(Request $request, string $gateway): RedirectResponse
    {
        abort_unless(isset(GatewayRegistry::GATEWAYS[$gateway]), 404);

        if ($gateway === 'stripe') {
            $data = $request->validate([
                'secret_key' => ['nullable', 'string', 'max:255', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
                'webhook_secret' => ['nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9]+$/'],
            ], ['secret_key.regex' => __('Klucz tajny Stripe zaczyna się od sk_live_, sk_test_ albo rk_….'), 'webhook_secret.regex' => __('Sekret webhooka Stripe zaczyna się od whsec_.')]);
            $enabled = $request->boolean('enabled');
            if ($enabled && ! GatewaySettings::has('stripe.secret_key') && empty($data['secret_key'])) {
                return back()->withErrors(['secret_key' => __('Podaj klucz tajny, żeby włączyć Stripe.')]);
            }
            GatewaySettings::save(['stripe.enabled' => $enabled, 'stripe.secret_key' => $data['secret_key'] ?? null, 'stripe.webhook_secret' => $data['webhook_secret'] ?? null]);
        } else {
            $data = $request->validate([
                'mode' => ['required', Rule::in(['sandbox', 'live'])],
                'client_id' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/'],
                'client_secret' => ['nullable', 'string', 'max:255'],
                'webhook_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
            ]);
            $enabled = $request->boolean('enabled');
            if ($enabled && (empty($data['client_id']) || (! GatewaySettings::has('paypal.client_secret') && empty($data['client_secret'])))) {
                return back()->withErrors(['client_id' => __('Podaj Client ID i Secret, żeby włączyć PayPal.')]);
            }
            GatewaySettings::save([
                'paypal.enabled' => $enabled,
                'paypal.mode' => $data['mode'],
                'paypal.client_id' => $data['client_id'] ?? '',
                'paypal.client_secret' => $data['client_secret'] ?? null,
                'paypal.webhook_id' => $data['webhook_id'] ?? '',
            ]);
        }
        AuditLog::record('settings.gateway', null, ['gateway' => $gateway, 'enabled' => $enabled], $request->user());

        return back()->with('status', __('Ustawienia bramki zapisane.'));
    }

    public function test(string $gateway): RedirectResponse
    {
        $driver = GatewayRegistry::get($gateway);
        abort_if($driver === null, 404);

        try {
            return back()->with('status', $driver->test());
        } catch (GatewayException $e) {
            return back()->withErrors(['gateway' => $e->getMessage()]);
        } catch (Throwable $e) {
            return back()->withErrors(['gateway' => __('Brak połączenia: :error', ['error' => $e->getMessage()])]);
        }
    }
}
