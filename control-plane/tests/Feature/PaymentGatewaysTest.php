<?php

namespace Tests\Feature;

use App\Domain\Billing\Billing;
use App\Domain\Billing\Gateways\GatewaySettings;
use App\Domain\Billing\Gateways\StripeGateway;
use App\Domain\Billing\InvoiceManager;
use App\Domain\Billing\Money;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Stripe Checkout i PayPal Orders v2: start, powrót, webhooki, weryfikacja kwot i podpisów. */
class PaymentGatewaysTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $admin;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Billing::save(['enabled' => true, 'currency' => 'PLN']);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->customer = User::factory()->create();
        // Doładowanie 50 zł — po zapłacie środki w portfelu.
        $this->invoice = app(InvoiceManager::class)->topup($this->customer, Money::parse('50'));
        GatewaySettings::save([
            'stripe.enabled' => true, 'stripe.secret_key' => 'sk_test_abc', 'stripe.webhook_secret' => 'whsec_test',
            'paypal.enabled' => true, 'paypal.mode' => 'sandbox', 'paypal.client_id' => 'CLIENT', 'paypal.client_secret' => 'SECRET', 'paypal.webhook_id' => 'WH123',
        ]);
    }

    private function stripeSession(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'cs_test_123', 'payment_status' => 'paid', 'currency' => 'pln', 'amount_total' => 5000,
            'metadata' => ['invoice_id' => (string) $this->invoice->id], 'payment_intent' => 'pi_1', 'livemode' => false,
        ], $overrides);
    }

    private function signedStripe(array $event, ?int $time = null): TestResponse
    {
        $payload = json_encode($event);
        $t = $time ?? time();
        $sig = hash_hmac('sha256', $t.'.'.$payload, 'whsec_test');

        return $this->call('POST', route('billing.webhook', 'stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}", 'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    public function test_sekrety_sa_szyfrowane(): void
    {
        $raw = Setting::get('gateway.stripe.secret_key');
        $this->assertNotSame('sk_test_abc', $raw);
        $this->assertSame('sk_test_abc', GatewaySettings::get('stripe.secret_key'));
        // Puste pole przy zapisie nie kasuje sekretu.
        GatewaySettings::save(['stripe.secret_key' => '']);
        $this->assertSame('sk_test_abc', GatewaySettings::get('stripe.secret_key'));
    }

    public function test_stripe_start_tworzy_sesje_z_kwota_faktury(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123'])]);

        $this->actingAs($this->customer)->post(route('panel.billing.pay', [$this->invoice, 'stripe']))
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        Http::assertSent(function (HttpRequest $r) {
            parse_str($r->body(), $form);

            return $r->hasHeader('Authorization', 'Bearer sk_test_abc')
                && $form['line_items'][0]['price_data']['unit_amount'] === '5000'
                && $form['line_items'][0]['price_data']['currency'] === 'pln'
                && $form['metadata']['invoice_id'] === (string) $this->invoice->id
                && str_ends_with($form['success_url'], 'session_id={CHECKOUT_SESSION_ID}');
        });
    }

    public function test_stripe_powrot_sprawdza_sesje_u_dostawcy(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_123' => Http::response($this->stripeSession())]);

        $this->actingAs($this->customer)->get(route('panel.billing.return', [$this->invoice, 'stripe']).'?session_id=cs_test_123')
            ->assertRedirect(route('panel.billing.invoice', $this->invoice))->assertSessionHas('status');

        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
        $this->assertSame(Money::parse('50'), $this->customer->fresh()->wallet_balance);
        $this->assertSame('cs_test_123', Payment::firstOrFail()->reference);
    }

    public function test_stripe_webhook_podpisany_i_idempotentny(): void
    {
        $event = ['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession()]];

        $this->signedStripe($event)->assertOk();
        $this->signedStripe($event)->assertOk();

        $this->assertSame(1, Payment::count());
        $this->assertSame(Money::parse('50'), $this->customer->fresh()->wallet_balance);
    }

    public function test_stripe_webhook_odrzuca_zly_lub_stary_podpis(): void
    {
        $event = ['type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession()]];
        $this->call('POST', route('billing.webhook', 'stripe'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=deadbeef', 'CONTENT_TYPE' => 'application/json'], json_encode($event))
            ->assertStatus(400);
        $this->signedStripe($event, time() - 3600)->assertStatus(400);
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);

        $gateway = app(StripeGateway::class);
        $this->assertTrue($gateway->validSignature('x', 't=100,v1='.hash_hmac('sha256', '100.x', 's'), 's', 150));
        $this->assertFalse($gateway->validSignature('x', 't=100,v1='.hash_hmac('sha256', '100.x', 's'), 's', 1000));
    }

    public function test_stripe_zla_kwota_lub_waluta_nie_oplaca_faktury(): void
    {
        $this->signedStripe(['type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession(['amount_total' => 100])]])->assertOk();
        $this->signedStripe(['type' => 'checkout.session.completed', 'data' => ['object' => $this->stripeSession(['id' => 'cs_test_9', 'currency' => 'eur'])]])->assertOk();
        $this->assertSame(2, AuditLog::query()->where('action', 'billing.gateway_mismatch')->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_paypal_zamowienie_akceptacja_i_przechwycenie(): void
    {
        $capture = ['id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'PLN', 'value' => '50.00'], 'custom_id' => (string) $this->invoice->id];
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'TOKEN', 'expires_in' => 3600]),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDER1234', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER1234']]], 201),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER1234/capture' => Http::response([
                'id' => 'ORDER1234', 'status' => 'COMPLETED',
                'purchase_units' => [['reference_id' => (string) $this->invoice->id, 'payments' => ['captures' => [$capture]]]],
            ], 201),
        ]);

        $this->actingAs($this->customer)->post(route('panel.billing.pay', [$this->invoice, 'paypal']))
            ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER1234');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/checkout/orders')
            && $r['purchase_units'][0]['amount'] === ['currency_code' => 'PLN', 'value' => '50.00']
            && $r['purchase_units'][0]['custom_id'] === (string) $this->invoice->id);

        $this->actingAs($this->customer)->get(route('panel.billing.return', [$this->invoice, 'paypal']).'?token=ORDER1234&PayerID=X')
            ->assertRedirect(route('panel.billing.invoice', $this->invoice));
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
        $this->assertSame('CAP1', Payment::firstOrFail()->reference);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/capture') && $r->hasHeader('PayPal-Request-Id', 'capture-ORDER1234'));
    }

    public function test_paypal_webhook_weryfikowany_przez_api(): void
    {
        $event = ['id' => 'WH-EV-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => 'CAP2', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'PLN', 'value' => '50.00'], 'custom_id' => (string) $this->invoice->id,
        ]];
        $headers = [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/cert', 'HTTP_PAYPAL_TRANSMISSION_ID' => 't1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig', 'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String(), 'CONTENT_TYPE' => 'application/json',
        ];

        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'TOKEN', 'expires_in' => 3600]),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::sequence()
                ->push(['verification_status' => 'FAILURE'])
                ->push(['verification_status' => 'SUCCESS']),
        ]);

        $this->call('POST', route('billing.webhook', 'paypal'), [], [], [], $headers, json_encode($event))->assertStatus(400);
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoice->fresh()->status);

        $this->call('POST', route('billing.webhook', 'paypal'), [], [], [], $headers, json_encode($event))->assertOk();
        $this->assertSame(Invoice::STATUS_PAID, $this->invoice->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'verify-webhook-signature')
            && $r['webhook_id'] === 'WH123' && $r['webhook_event']['id'] === 'WH-EV-1');

        // Bez nagłówków podpisu — odrzucone bez pytania PayPal.
        $this->call('POST', route('billing.webhook', 'paypal'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($event))->assertStatus(400);
    }

    public function test_cudza_faktura_i_wylaczona_bramka(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('panel.billing.pay', [$this->invoice, 'stripe']))->assertNotFound();
        $this->actingAs($other)->get(route('panel.billing.return', [$this->invoice, 'stripe']))->assertNotFound();

        GatewaySettings::save(['stripe.enabled' => false]);
        $this->actingAs($this->customer)->post(route('panel.billing.pay', [$this->invoice, 'stripe']))->assertNotFound();
        $this->actingAs($this->customer)->get(route('panel.billing.invoice', $this->invoice))->assertOk()->assertSee('PayPal')->assertDontSee('Stripe (');
    }

    public function test_ustawienia_bramek_tylko_dla_administratora(): void
    {
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT, 'permissions' => ['admin.billing']]);
        $this->actingAs($support)->get(route('panel.admin.billing.gateways'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('panel.admin.billing.gateways'))->assertOk()
            ->assertSee(route('billing.webhook', 'stripe'))->assertDontSee('sk_test_abc')->assertDontSee('SECRET');
        $this->actingAs($this->admin)->put(route('panel.admin.billing.gateways.update', 'stripe'), ['enabled' => 1, 'secret_key' => 'zly klucz'])
            ->assertSessionHasErrors('secret_key');
        $this->actingAs($this->admin)->put(route('panel.admin.billing.gateways.update', 'stripe'), ['enabled' => 1, 'secret_key' => 'sk_live_NEW'])->assertRedirect();
        $this->assertSame('sk_live_NEW', GatewaySettings::get('stripe.secret_key'));
        $this->assertSame('whsec_test', GatewaySettings::get('stripe.webhook_secret'));

        Http::fake(['api.stripe.com/v1/balance' => Http::response(['livemode' => true])]);
        $this->actingAs($this->admin)->post(route('panel.admin.billing.gateways.test', 'stripe'))->assertSessionHas('status');

        $this->actingAs($this->admin)->put(route('panel.admin.billing.gateways.update', 'paypal'), ['enabled' => 1, 'mode' => 'live', 'client_id' => 'ABC', 'webhook_id' => 'WH9'])->assertRedirect();
        $this->assertSame('live', GatewaySettings::get('paypal.mode'));
        $this->assertSame('SECRET', GatewaySettings::get('paypal.client_secret'));
    }
}
