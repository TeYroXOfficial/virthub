<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\InvoiceManager;
use App\Domain\Billing\Money;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PayPal Orders v2 przez REST API (bez SDK): zamówienie → akceptacja klienta
 * → przechwycenie (capture) przy powrocie albo z webhooka. Płatność liczy
 * się raz — po identyfikatorze przechwycenia; kwota i waluta muszą się zgadzać.
 */
class PayPalGateway implements PaymentGateway
{
    /** Waluty bez części ułamkowej w PayPal. */
    private const ZERO_DECIMAL = ['HUF', 'JPY', 'TWD'];

    public function __construct(private readonly InvoiceManager $invoices) {}

    public function key(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function available(): bool
    {
        return GatewaySettings::enabled('paypal') && GatewaySettings::has('paypal.client_id') && GatewaySettings::has('paypal.client_secret');
    }

    public function start(Invoice $invoice): string
    {
        $response = $this->http()->post($this->base().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $invoice->id,
                'custom_id' => (string) $invoice->id,
                'description' => mb_substr(__('Faktura :number', ['number' => $invoice->number]), 0, 127),
                'amount' => ['currency_code' => $invoice->currency, 'value' => $this->value($invoice->total, $invoice->currency)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => mb_substr((string) config('virthub.brand'), 0, 127),
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => route('panel.billing.return', [$invoice, 'paypal']),
                'cancel_url' => route('panel.billing.invoice', $invoice),
            ]]],
        ]);

        $approve = collect($response->json('links', []))->first(fn ($l) => in_array($l['rel'] ?? '', ['payer-action', 'approve'], true));
        if (! $response->successful() || ! isset($approve['href'])) {
            Log::warning('PayPal: nie udało się utworzyć zamówienia', ['invoice' => $invoice->id, 'status' => $response->status(), 'body' => $response->json()]);
            throw new GatewayException(__('PayPal odrzucił płatność: :error', ['error' => $response->json('message') ?? $response->status()]));
        }

        return $approve['href'];
    }

    public function complete(Invoice $invoice, Request $request): ?Payment
    {
        $orderId = (string) $request->query('token', '');
        if (! preg_match('/^[A-Z0-9]{8,40}$/', $orderId)) {
            return null;
        }

        return $this->capture($orderId, $invoice);
    }

    public function webhook(Request $request): int
    {
        $raw = $request->getContent();
        $event = json_decode($raw, true);
        if (! is_array($event) || ! $this->verified($request, $raw)) {
            return 400;
        }

        try {
            $resource = $event['resource'] ?? [];
            switch ($event['event_type'] ?? '') {
                // Klient zaakceptował, ale nie wrócił do panelu — przechwytujemy sami.
                case 'CHECKOUT.ORDER.APPROVED':
                    $invoice = Invoice::query()->find((int) ($resource['purchase_units'][0]['custom_id'] ?? 0));
                    if ($invoice !== null && isset($resource['id'])) {
                        $this->capture((string) $resource['id'], $invoice);
                    }
                    break;
                case 'PAYMENT.CAPTURE.COMPLETED':
                    $invoice = Invoice::query()->find((int) ($resource['custom_id'] ?? 0));
                    if ($invoice !== null) {
                        $this->settle($resource, $invoice);
                    }
                    break;
            }
        } catch (GatewayException $e) {
            // Trwała niezgodność (kwota, waluta, faktura) — zapisana w dzienniku, ponawianie nic nie da.
            Log::warning('PayPal webhook: płatność odrzucona', ['event' => $event['id'] ?? null, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('PayPal webhook: nie udało się zaksięgować płatności', ['event' => $event['id'] ?? null, 'error' => $e->getMessage()]);

            return 500; // PayPal ponowi
        }

        return 200;
    }

    public function test(): string
    {
        Cache::forget($this->tokenCacheKey());
        $this->token();

        return GatewaySettings::get('paypal.mode', 'sandbox') === 'live'
            ? __('Połączono z PayPal (tryb produkcyjny).')
            : __('Połączono z PayPal (sandbox).');
    }

    /** Przechwycenie zamówienia (idempotentne po stronie PayPal dzięki PayPal-Request-Id). */
    private function capture(string $orderId, Invoice $invoice): ?Payment
    {
        $response = $this->http()->withHeaders(['PayPal-Request-Id' => 'capture-'.$orderId])
            ->withBody('{}', 'application/json')
            ->post($this->base().'/v2/checkout/orders/'.$orderId.'/capture');

        if ($response->status() === 422 && ($response->json('details.0.issue') === 'ORDER_ALREADY_CAPTURED')) {
            $response = $this->http()->get($this->base().'/v2/checkout/orders/'.$orderId);
        }
        if (! $response->successful()) {
            Log::warning('PayPal: przechwycenie nieudane', ['order' => $orderId, 'status' => $response->status(), 'body' => $response->json()]);
            throw new GatewayException(__('PayPal nie potwierdził płatności: :error', ['error' => $response->json('details.0.description') ?? $response->json('message') ?? $response->status()]));
        }

        $unit = $response->json('purchase_units.0', []);
        if ((string) ($unit['custom_id'] ?? $unit['reference_id'] ?? '') !== (string) $invoice->id) {
            throw new GatewayException(__('Płatność dotyczy innej faktury.'));
        }
        $capture = collect($unit['payments']['captures'] ?? [])->firstWhere('status', 'COMPLETED');

        return $capture ? $this->settle($capture + ['custom_id' => (string) $invoice->id], $invoice) : null;
    }

    /** Zaksięgowanie przechwycenia ze sprawdzeniem faktury, kwoty i waluty. */
    private function settle(array $capture, Invoice $invoice): ?Payment
    {
        if (($capture['status'] ?? '') !== 'COMPLETED' || ! isset($capture['id'])) {
            return null;
        }
        if ((string) ($capture['custom_id'] ?? '') !== (string) $invoice->id) {
            throw new GatewayException(__('Płatność dotyczy innej faktury.'));
        }
        $currency = strtoupper((string) ($capture['amount']['currency_code'] ?? ''));
        $value = (string) ($capture['amount']['value'] ?? '');
        $amount = Money::valid($value) ? Money::parse($value) : 0;
        if ($currency !== $invoice->currency || ($amount < $invoice->total && $invoice->isUnpaid())) {
            AuditLog::record('billing.gateway_mismatch', $invoice, ['gateway' => 'paypal', 'capture' => $capture['id'], 'amount' => $amount, 'currency' => $currency], null);
            throw new GatewayException(__('Kwota albo waluta płatności nie zgadza się z fakturą.'));
        }

        return $this->invoices->markPaid($invoice, 'paypal', (string) $capture['id'], $amount, $currency, null, [
            'order_id' => $capture['supplementary_data']['related_ids']['order_id'] ?? null,
        ]);
    }

    /** Weryfikacja webhooka przez API PayPal (wymaga identyfikatora webhooka z panelu PayPal). */
    private function verified(Request $request, string $raw): bool
    {
        $webhookId = GatewaySettings::get('paypal.webhook_id');
        if ($webhookId === '') {
            return false;
        }
        $headers = [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
        ];
        if (in_array(null, $headers, true)) {
            return false;
        }
        // Zdarzenie wklejamy bez ponownego kodowania — PayPal liczy podpis z oryginału.
        $body = rtrim(json_encode($headers + ['webhook_id' => $webhookId], JSON_UNESCAPED_SLASHES), '}').',"webhook_event":'.$raw.'}';

        try {
            $response = $this->http()->withBody($body, 'application/json')->post($this->base().'/v1/notifications/verify-webhook-signature');
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    private function value(int $amount, string $currency): string
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? Money::toDecimal($amount, 0) : Money::toDecimal($amount, 2);
    }

    private function base(): string
    {
        return GatewaySettings::get('paypal.mode', 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->asJson()->timeout(20);
    }

    private function token(): string
    {
        $clientId = GatewaySettings::get('paypal.client_id');
        $secret = GatewaySettings::get('paypal.client_secret');
        if ($clientId === '' || $secret === '') {
            throw new GatewayException(__('PayPal nie jest skonfigurowany.'));
        }

        $cached = Cache::get($this->tokenCacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $response = Http::asForm()->withBasicAuth($clientId, $secret)->acceptJson()->timeout(20)
            ->post($this->base().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new GatewayException(__('PayPal odrzucił dane logowania: :error', ['error' => $response->json('error_description') ?? $response->status()]));
        }
        Cache::put($this->tokenCacheKey(), $token, max(60, (int) $response->json('expires_in', 3600) - 120));

        return $token;
    }

    private function tokenCacheKey(): string
    {
        return 'paypal.token.'.sha1(GatewaySettings::get('paypal.mode', 'sandbox').'|'.GatewaySettings::get('paypal.client_id'));
    }
}
