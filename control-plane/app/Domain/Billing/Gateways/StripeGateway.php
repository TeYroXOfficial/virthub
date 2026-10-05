<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\InvoiceManager;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stripe Checkout przez REST API (bez SDK). Płatność zaliczamy dopiero po
 * sprawdzeniu sesji u Stripe — przy powrocie klienta albo z podpisanego
 * webhooka; identyfikator sesji gwarantuje, że liczy się raz.
 */
class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    /** Waluty bez części ułamkowej w Stripe. */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Maksymalny wiek podpisu webhooka (sekundy) — ochrona przed powtórką. */
    private const TOLERANCE = 300;

    public function __construct(private readonly InvoiceManager $invoices) {}

    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        // Metody (karta, BLIK, Przelewy24…) wybiera się w panelu Stripe.
        return __('Stripe (karta i inne metody)');
    }

    public function available(): bool
    {
        return GatewaySettings::enabled('stripe') && GatewaySettings::has('stripe.secret_key');
    }

    public function start(Invoice $invoice): string
    {
        $returnUrl = route('panel.billing.return', [$invoice, 'stripe']);
        $response = $this->http()->asForm()->post(self::API.'/checkout/sessions', [
            'mode' => 'payment',
            // {CHECKOUT_SESSION_ID} podstawia Stripe — nie może być zakodowane.
            'success_url' => $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('panel.billing.invoice', $invoice),
            'client_reference_id' => (string) $invoice->id,
            'customer_email' => $invoice->user?->email,
            'metadata' => ['invoice_id' => (string) $invoice->id, 'invoice_number' => $invoice->number],
            'payment_intent_data' => ['metadata' => ['invoice_id' => (string) $invoice->id], 'description' => $invoice->number],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($invoice->currency),
                    'unit_amount' => $this->minorUnits($invoice->total, $invoice->currency),
                    'product_data' => ['name' => __('Faktura :number', ['number' => $invoice->number])],
                ],
            ]],
        ]);

        if (! $response->successful() || ! is_string($response->json('url'))) {
            Log::warning('Stripe: nie udało się utworzyć sesji', ['invoice' => $invoice->id, 'status' => $response->status(), 'error' => $response->json('error.message')]);
            throw new GatewayException(__('Stripe odrzucił płatność: :error', ['error' => $response->json('error.message') ?? $response->status()]));
        }

        return $response->json('url');
    }

    public function complete(Invoice $invoice, Request $request): ?Payment
    {
        $sessionId = (string) $request->query('session_id', '');
        if (! preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
            return null;
        }
        $response = $this->http()->get(self::API.'/checkout/sessions/'.$sessionId);
        if (! $response->successful()) {
            throw new GatewayException(__('Nie udało się sprawdzić płatności w Stripe.'));
        }

        return $this->settle($response->json(), $invoice);
    }

    public function webhook(Request $request): int
    {
        $secret = GatewaySettings::get('stripe.webhook_secret');
        if ($secret === '' || ! $this->validSignature($request->getContent(), (string) $request->header('Stripe-Signature'), $secret)) {
            return 400;
        }
        $event = json_decode($request->getContent(), true);
        if (! is_array($event)) {
            return 400;
        }

        if (in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $session = $event['data']['object'] ?? [];
            $invoice = Invoice::query()->find((int) ($session['metadata']['invoice_id'] ?? 0));
            if ($invoice !== null) {
                try {
                    $this->settle($session, $invoice);
                } catch (GatewayException $e) {
                    // Trwała niezgodność (kwota, waluta) — zapisana w dzienniku, ponawianie nic nie da.
                    Log::warning('Stripe webhook: płatność odrzucona', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
                } catch (Throwable $e) {
                    Log::error('Stripe webhook: nie udało się zaksięgować płatności', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);

                    return 500; // Stripe ponowi
                }
            }
        }

        return 200;
    }

    public function test(): string
    {
        $response = $this->http()->get(self::API.'/balance');
        if (! $response->successful()) {
            throw new GatewayException(__('Stripe: :error', ['error' => $response->json('error.message') ?? $response->status()]));
        }

        return $response->json('livemode') ? __('Połączono z Stripe (tryb produkcyjny).') : __('Połączono z Stripe (tryb testowy).');
    }

    /** Sprawdza sesję Checkout i księguje płatność, gdy się zgadza z fakturą. */
    private function settle(array $session, Invoice $invoice): ?Payment
    {
        if (($session['payment_status'] ?? '') !== 'paid') {
            return null;
        }
        if ((string) ($session['metadata']['invoice_id'] ?? '') !== (string) $invoice->id) {
            throw new GatewayException(__('Płatność dotyczy innej faktury.'));
        }
        $currency = strtoupper((string) ($session['currency'] ?? ''));
        $amount = $this->fromMinorUnits((int) ($session['amount_total'] ?? 0), $currency);
        if ($currency !== $invoice->currency || ($amount < $invoice->total && $invoice->isUnpaid())) {
            AuditLog::record('billing.gateway_mismatch', $invoice, ['gateway' => 'stripe', 'session' => $session['id'] ?? null, 'amount' => $amount, 'currency' => $currency], null);
            throw new GatewayException(__('Kwota albo waluta płatności nie zgadza się z fakturą.'));
        }

        return $this->invoices->markPaid($invoice, 'stripe', (string) $session['id'], $amount, $currency, null, [
            'payment_intent' => $session['payment_intent'] ?? null,
            'livemode' => $session['livemode'] ?? null,
        ]);
    }

    /** Podpis Stripe-Signature: t=…,v1=… — HMAC-SHA256 z „t.payload”. */
    public function validSignature(string $payload, string $header, string $secret, ?int $now = null): bool
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function minorUnits(int $amount, string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? intdiv($amount, 10000) : intdiv($amount, 100);
    }

    private function fromMinorUnits(int $minor, string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? $minor * 10000 : $minor * 100;
    }

    private function http(): PendingRequest
    {
        $key = GatewaySettings::get('stripe.secret_key');
        if ($key === '') {
            throw new GatewayException(__('Stripe nie jest skonfigurowany.'));
        }

        return Http::withToken($key)->acceptJson()->timeout(20)->withHeaders(['Stripe-Version' => '2024-06-20']);
    }
}
