<?php

namespace Tests\Feature;

use App\Domain\Apps\EggImporter;
use App\Domain\Billing\Billing;
use App\Domain\Billing\Cycle;
use App\Domain\Billing\InsufficientFunds;
use App\Domain\Billing\InvoiceManager;
use App\Domain\Billing\Money;
use App\Domain\Billing\ServiceManager;
use App\Domain\Billing\Wallet;
use App\Enums\ServerState;
use App\Mail\TemplatedMail;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\BillingService;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use App\Models\Invoice;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Server;
use App\Models\User;
use App\Models\VpsPackage;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Wbudowany billing: kwoty, portfel, faktury, zamówienia, cykle, zawieszanie i sklep. */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Product $product;

    private OsTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->customer = User::factory()->create();

        $hypervisor = Hypervisor::factory()->create(['cpu_cores_total' => 16, 'ram_mb_total' => 32768, 'disk_gb_total' => 500]);
        $pool = IpPool::factory()->create(['hypervisor_id' => $hypervisor->id]);
        IpAddress::factory()->count(5)->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $hypervisor->id]);
        $package = VpsPackage::factory()->create(['slug' => 'vps-s', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20, 'ip_count' => 1]);
        $this->template = OsTemplate::factory()->create();

        Billing::save(['enabled' => true, 'currency' => 'PLN', 'tax_rate' => '23', 'prices_include_tax' => true]);
        $category = ProductCategory::create(['name' => 'VPS', 'slug' => 'vps']);
        $this->product = Product::create([
            'product_category_id' => $category->id, 'name' => 'VPS S', 'type' => Product::TYPE_VPS, 'vps_package_id' => $package->id,
        ]);
        $this->product->prices()->createMany([
            ['cycle' => 'monthly', 'amount' => Money::parse('49')],
            ['cycle' => 'hourly', 'amount' => Money::parse('0.0700')],
        ]);
    }

    private function fund(User $user, string $amount): void
    {
        app(Wallet::class)->adjust($user, Money::parse($amount), 'test', $this->admin);
    }

    private function config(string $host = 'vps1.example.com'): array
    {
        return ['template_id' => $this->template->id, 'hostname' => $host, 'ssh_keys' => [], 'location_id' => null];
    }

    public function test_kwoty_bez_bledow_zaokraglen(): void
    {
        $this->assertSame(125000, Money::parse('12,50'));
        $this->assertSame(700, Money::parse('0.07'));
        $this->assertSame(-34000, Money::parse('-3.4'));
        $this->assertSame(125100, Money::roundCents(125050));
        $this->assertSame(125000, Money::roundCents(125049));
        $this->assertSame("1\u{00A0}234,50 PLN", Money::format(Money::parse('1234.5')));
        $this->assertSame('0,07 PLN', Money::format(700));
        $this->assertSame('0,0125 PLN', Money::format(125));
        $this->assertSame('12.50', Money::toDecimal(125000));
        $this->assertFalse(Money::valid('12,345,1'));
        $this->assertFalse(Money::valid('1e5'));
    }

    public function test_zamowienie_miesieczne_oplacone_z_portfela_tworzy_maszyne(): void
    {
        $this->fund($this->customer, '100');

        [$service, $invoice] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'monthly', $this->config(), true);

        $this->assertSame(BillingService::STATUS_ACTIVE, $service->status);
        $this->assertNotNull($service->server_id);
        $this->assertSame('billing:'.$service->id, Server::find($service->server_id)->billing_reference);
        $this->assertTrue($service->next_due_at->equalTo(now()->addMonthNoOverflow()));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        // Ceny brutto: 49,00 = 39,84 netto + 9,16 VAT.
        $this->assertSame(Money::parse('49'), $invoice->total);
        $this->assertSame(Money::parse('39.84'), $invoice->subtotal);
        $this->assertSame(Money::parse('9.16'), $invoice->tax);
        $this->assertSame('FV/2026/0001', $invoice->number);
        $this->assertSame(Money::parse('51'), $this->customer->fresh()->wallet_balance);
        $this->assertSame(1, Payment::query()->where('gateway', 'wallet')->count());
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'invoice.paid');
        Mail::assertNotQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'invoice.created');
    }

    public function test_bez_srodkow_powstaje_faktura_a_usluga_startuje_po_zaplacie(): void
    {
        [$service, $invoice] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'monthly', $this->config(), true);

        $this->assertSame(BillingService::STATUS_PENDING, $service->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame(0, Server::count());
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'invoice.created' && $m->hasTo($this->customer->email));

        // Ta sama płatność zgłoszona dwa razy (powrót z bramki + webhook) liczy się raz.
        $invoices = app(InvoiceManager::class);
        $invoices->markPaid($invoice, 'manual', 'PRZELEW-1');
        $invoices->markPaid($invoice->fresh(), 'manual', 'PRZELEW-1');

        $this->assertSame(1, Payment::count());
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame(1, Server::count());
        $this->assertSame(0, $this->customer->fresh()->wallet_balance);
    }

    public function test_nadplata_i_platnosc_za_anulowana_fakture_trafiaja_do_portfela(): void
    {
        [, $invoice] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'monthly', $this->config(), false);
        $invoices = app(InvoiceManager::class);
        $invoices->markPaid($invoice, 'manual', 'A', Money::parse('50'));
        $this->assertSame(Money::parse('1'), $this->customer->fresh()->wallet_balance);

        [, $second] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'monthly', $this->config('vps2.example.com'), false);
        $invoices->cancel($second);
        $invoices->markPaid($second->fresh(), 'manual', 'B', Money::parse('49'));
        $this->assertSame(Money::parse('50'), $this->customer->fresh()->wallet_balance);
        $this->assertSame(1, Server::count(), 'anulowane zamówienie się nie uruchamia');

        $this->expectException(\DomainException::class);
        $invoices->markPaid($invoice->fresh(), 'manual', 'C', null, 'EUR');
    }

    public function test_ceny_netto_doliczaja_vat_i_numeracja_jest_ciagla(): void
    {
        Billing::save(['prices_include_tax' => false, 'invoice_prefix' => 'INV-{Y}-']);
        $invoices = app(InvoiceManager::class);
        $a = $invoices->create($this->customer, [['kind' => 'other', 'description' => 'x', 'amount' => Money::parse('100')]]);
        $b = $invoices->create($this->customer, [['kind' => 'other', 'description' => 'y', 'amount' => Money::parse('10.01')]]);

        $this->assertSame([Money::parse('100'), Money::parse('23'), Money::parse('123')], [$a->subtotal, $a->tax, $a->total]);
        $this->assertSame(Money::parse('12.31'), $b->total); // 10,01 + 2,30
        $this->assertSame('INV-2026-0001', $a->number);
        $this->assertSame('INV-2026-0002', $b->number);
    }

    public function test_godzinowe_naliczanie_zawieszenie_i_wznowienie_po_doladowaniu(): void
    {
        Billing::save(['min_balance_metered' => '5']);
        $manager = app(ServiceManager::class);

        try {
            $manager->checkout($this->customer, $this->product, 'hourly', $this->config(), true);
            $this->fail('Bez salda zamówienie godzinowe nie przechodzi');
        } catch (InsufficientFunds) {
        }
        $this->assertSame(0, Server::count());

        $this->fund($this->customer, '5.20');
        [$service] = $manager->checkout($this->customer, $this->product, 'hourly', $this->config(), true);
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->status);
        $this->assertSame(Money::parse('5.13'), $this->customer->fresh()->wallet_balance);

        // Trzy godziny później: trzy kolejne opłaty.
        $this->travel(3)->hours();
        $this->assertSame(3, $manager->chargeMetered());
        $this->assertSame(Money::parse('4.92'), $this->customer->fresh()->wallet_balance);
        $this->assertSame(0, $manager->chargeMetered(), 'drugi przebieg niczego nie nalicza');

        // Saldo poniżej zera → zawieszenie.
        app(Wallet::class)->adjust($this->customer, -Money::parse('4.90'), 'test', $this->admin);
        $this->travel(1)->hours();
        $manager->chargeMetered();
        $this->assertTrue($this->customer->fresh()->wallet_balance < 0);
        $this->assertSame(BillingService::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertNotNull(Server::find($service->server_id)->suspended_at);
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'service.suspended_unpaid');

        // Doładowanie → wznowienie, naliczanie od nowa.
        $this->actingAs($this->admin)->post(route('panel.admin.billing.customer.wallet', $this->customer), ['amount' => '10', 'description' => 'Przelew'])->assertRedirect();
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertNull(Server::find($service->server_id)->suspended_at);
    }

    public function test_ostrzezenie_o_niskim_saldzie_raz_do_doladowania(): void
    {
        Billing::save(['min_balance_metered' => '0', 'low_balance_hours' => '24']);
        $this->fund($this->customer, '1');
        app(ServiceManager::class)->checkout($this->customer, $this->product, 'hourly', $this->config(), true);

        $this->assertSame(1, app(ServiceManager::class)->warnLowBalance());
        $this->assertSame(0, app(ServiceManager::class)->warnLowBalance());
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'wallet.low_balance');
    }

    public function test_odnowienie_przypomnienie_zawieszenie_i_odwieszenie_po_zaplacie(): void
    {
        Billing::save(['renewal_days' => '7', 'suspend_days' => '3', 'terminate_days' => '14', 'auto_pay' => true]);
        $manager = app(ServiceManager::class);
        $this->fund($this->customer, '49');
        [$service] = $manager->checkout($this->customer, $this->product, 'monthly', $this->config(), true);
        $dueAt = $service->next_due_at->copy();

        // 8 dni przed końcem — jeszcze nic; 6 dni przed — faktura odnowienia.
        $this->travelTo($dueAt->copy()->subDays(8));
        $this->assertSame(0, $manager->createRenewals());
        $this->travelTo($dueAt->copy()->subDays(6));
        $this->assertSame(1, $manager->createRenewals());
        $this->assertSame(0, $manager->createRenewals(), 'bez duplikatów');
        $renewal = Invoice::query()->latest('id')->first();
        $this->assertSame(Invoice::STATUS_UNPAID, $renewal->status);
        $this->assertTrue($renewal->due_at->equalTo($dueAt));

        $this->travelTo($dueAt->copy()->subDay());
        $manager->processOverdue();
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'invoice.reminder');

        // Po terminie + 3 dni → zawieszenie.
        $this->travelTo($dueAt->copy()->addDays(4));
        $manager->processOverdue();
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'invoice.overdue');
        $this->assertSame(BillingService::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertSame('unpaid', $service->fresh()->suspend_reason);

        // Doładowanie opłaca zaległą fakturę z portfela → odwieszenie i kolejny okres.
        $this->fund($this->customer, '49');
        app(ServiceManager::class)->resumeAfterTopup($this->customer->fresh());
        $service->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $renewal->fresh()->status);
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->status);
        $this->assertTrue($service->next_due_at->equalTo($dueAt->copy()->addMonthNoOverflow()));
    }

    public function test_usuniecie_po_dniach_zawieszenia_za_brak_platnosci(): void
    {
        Billing::save(['suspend_days' => '0', 'terminate_days' => '5']);
        $manager = app(ServiceManager::class);
        $this->fund($this->customer, '49');
        [$service] = $manager->checkout($this->customer, $this->product, 'monthly', $this->config(), true);

        $this->travelTo($service->next_due_at->copy()->subDays(3));
        $manager->createRenewals();
        $this->travelTo($service->next_due_at->copy()->addHour());
        $manager->processOverdue();
        $this->assertSame(BillingService::STATUS_SUSPENDED, $service->fresh()->status);

        $this->travel(6)->days();
        $manager->processOverdue();
        $service->refresh();
        $this->assertSame(BillingService::STATUS_TERMINATED, $service->status);
        $this->assertSame(ServerState::Deleting, Server::find($service->server_id)->state);
        $this->assertSame(Invoice::STATUS_CANCELLED, Invoice::query()->latest('id')->first()->status);
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'service.terminated_unpaid');
    }

    public function test_rezygnacja_z_koncem_okresu(): void
    {
        $manager = app(ServiceManager::class);
        $this->fund($this->customer, '49');
        [$service] = $manager->checkout($this->customer, $this->product, 'monthly', $this->config(), true);

        $this->actingAs($this->customer)->post(route('panel.billing.service.cancel', $service), ['confirm' => 1])->assertRedirect();
        $this->assertTrue($service->fresh()->cancel_at_period_end);
        $this->travelTo($service->next_due_at->copy()->subDays(3));
        $this->assertSame(0, $manager->createRenewals(), 'brak faktury odnowienia po rezygnacji');
        $this->travelTo($service->next_due_at->copy()->addMinute());
        $this->assertSame(1, $manager->endCancelled());
        $this->assertSame(BillingService::STATUS_TERMINATED, $service->fresh()->status);
    }

    public function test_nieudane_uruchomienie_zwraca_pieniadze(): void
    {
        $this->fund($this->customer, '49');
        $this->template->update(['is_active' => false]);

        [$service] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'monthly', $this->config(), true);

        $this->assertSame(BillingService::STATUS_CANCELLED, $service->status);
        $this->assertNotNull($service->last_error);
        $this->assertSame(Money::parse('49'), $this->customer->fresh()->wallet_balance);
    }

    public function test_maszyna_usunieta_poza_billingiem_konczy_usluge(): void
    {
        $this->fund($this->customer, '49');
        [$service] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'monthly', $this->config(), true);
        Server::find($service->server_id)->delete();

        $this->assertSame(1, app(ServiceManager::class)->syncDeleted());
        $this->assertSame(BillingService::STATUS_TERMINATED, $service->fresh()->status);
    }

    public function test_doladowanie_portfela_faktura_bez_vat(): void
    {
        Billing::save(['min_topup' => '10', 'max_topup' => '1000']);
        $this->actingAs($this->customer)->post(route('panel.billing.topup'), ['amount' => '5'])->assertSessionHasErrors('amount');
        $this->actingAs($this->customer)->post(route('panel.billing.topup'), ['amount' => '50,00'])->assertRedirect();
        $invoice = Invoice::query()->where('type', Invoice::TYPE_TOPUP)->firstOrFail();
        $this->assertSame([0, Money::parse('50')], [$invoice->tax, $invoice->total]);

        $this->actingAs($this->customer)->post(route('panel.billing.invoice.wallet', $invoice))->assertSessionHasErrors('payment');
        $this->actingAs($this->admin)->post(route('panel.admin.billing.invoice.action', $invoice), ['action' => 'mark_paid', 'reference' => 'PRZELEW 12/10'])->assertRedirect();
        $this->assertSame(Money::parse('50'), $this->customer->fresh()->wallet_balance);
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => $m->templateKey === 'wallet.topup');
    }

    public function test_zwrot_faktury_do_portfela(): void
    {
        $this->fund($this->customer, '49');
        [, $invoice] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'monthly', $this->config(), true);

        $this->actingAs($this->admin)->post(route('panel.admin.billing.invoice.action', $invoice), ['action' => 'refund'])->assertRedirect();
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame(Money::parse('49'), $this->customer->fresh()->wallet_balance);
    }

    public function test_sklep_zastepuje_bezposrednie_zamawianie_klienta(): void
    {
        $this->actingAs($this->customer)->get(route('panel.servers.create'))->assertRedirect(route('panel.store'));
        $this->actingAs($this->customer)->post(route('panel.servers.store'), ['package' => 'vps-s', 'template' => $this->template->id, 'hostname' => 'a.example.com'])->assertForbidden();
        $this->actingAs($this->customer)->postJson('/api/v1/servers', ['package' => 'vps-s', 'template' => $this->template->id, 'hostname' => 'a.example.com'])->assertForbidden();
        $this->actingAs($this->customer)->get(route('panel.apps.create'))->assertRedirect(route('panel.store'));
        // Personel zamawia bezpośrednio jak dotąd.
        $this->actingAs($this->admin)->get(route('panel.servers.create'))->assertOk();

        Billing::save(['enabled' => false]);
        $this->actingAs($this->customer)->get(route('panel.servers.create'))->assertOk();
        $this->actingAs($this->customer)->get(route('panel.store'))->assertNotFound();
    }

    public function test_zamowienie_przez_sklep(): void
    {
        $this->actingAs($this->customer)->get(route('panel.store'))->assertOk()->assertSee('VPS S')->assertSee('49,00 PLN', false)->assertSee('godzinowo');
        $this->actingAs($this->customer)->get(route('panel.store.product', $this->product))->assertOk()->assertSee($this->template->name);

        $this->actingAs($this->customer)->post(route('panel.store.order', $this->product), [
            'cycle' => 'monthly', 'template' => $this->template->id, 'hostname' => 'zly host', 'payment' => 'invoice', 'accept' => 1,
        ])->assertSessionHasErrors('hostname');

        $response = $this->actingAs($this->customer)->post(route('panel.store.order', $this->product), [
            'cycle' => 'monthly', 'template' => $this->template->id, 'hostname' => 'shop.example.com', 'payment' => 'invoice', 'accept' => 1,
        ]);
        $invoice = Invoice::query()->firstOrFail();
        $response->assertRedirect(route('panel.billing.invoice', $invoice));
        $this->actingAs($this->customer)->get(route('panel.billing.invoice', $invoice))->assertOk()->assertSee($invoice->number)->assertSee('49,00 PLN');
        $this->actingAs($this->customer)->get(route('panel.billing'))->assertOk()->assertSee('shop.example.com');

        // Cudza faktura i usługa — niewidoczne.
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('panel.billing.invoice', $invoice))->assertNotFound();
        $this->actingAs($other)->get(route('panel.billing.service', BillingService::firstOrFail()))->assertNotFound();

        // Limit sztuk.
        $this->product->update(['stock' => 1]);
        $this->actingAs($this->customer)->post(route('panel.store.order', $this->product), [
            'cycle' => 'monthly', 'template' => $this->template->id, 'hostname' => 'shop2.example.com', 'payment' => 'invoice', 'accept' => 1,
        ])->assertSessionHasErrors('payment');
    }

    public function test_administracja_katalogu_i_stron(): void
    {
        $this->actingAs($this->customer)->get(route('panel.admin.billing'))->assertForbidden();

        $this->actingAs($this->admin)->post(route('panel.admin.billing.categories.store'), ['name' => 'Serwery gier', 'is_active' => 1])->assertRedirect();
        $category = ProductCategory::query()->where('slug', 'serwery-gier')->firstOrFail();
        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), [
            'product_category_id' => $category->id, 'name' => 'VPS M', 'type' => 'vps', 'vps_package_id' => $this->product->vps_package_id,
            'prices' => ['monthly' => '79,99', 'annually' => '799'], 'setup_fee' => '10', 'is_active' => 1,
        ])->assertRedirect(route('panel.admin.billing.catalog'));
        $product = Product::query()->where('name', 'VPS M')->firstOrFail();
        $this->assertSame(['monthly' => Money::parse('79.99'), 'annually' => Money::parse('799')], $product->priceMap());
        $this->assertSame(Money::parse('10'), $product->setup_fee);

        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), [
            'product_category_id' => $category->id, 'name' => 'Bez ceny', 'type' => 'vps', 'vps_package_id' => $this->product->vps_package_id, 'prices' => [],
        ])->assertSessionHasErrors('prices');

        $this->actingAs($this->admin)->put(route('panel.admin.billing.products.update', $product), [
            'product_category_id' => $category->id, 'name' => 'VPS M', 'type' => 'vps', 'vps_package_id' => $this->product->vps_package_id,
            'prices' => ['monthly' => '89'],
        ])->assertRedirect();
        $this->assertSame(['monthly' => Money::parse('89')], $product->fresh()->load('prices')->priceMap());
        $this->assertFalse($product->fresh()->is_active);

        $this->actingAs($this->admin)->put(route('panel.admin.billing.settings.update'), [
            'enabled' => 1, 'currency' => 'eur', 'tax_rate' => '8', 'invoice_prefix' => 'F/{Y}/', 'due_days' => 5, 'renewal_days' => 5,
            'reminder_days' => 1, 'suspend_days' => 2, 'terminate_days' => 10, 'min_topup' => '5', 'max_topup' => '500',
            'min_balance_metered' => '2', 'low_balance_hours' => 12,
        ])->assertRedirect();
        $this->assertSame('EUR', Billing::currency());
        $this->assertSame(800, Billing::taxRate());
        $this->assertFalse(Billing::pricesIncludeTax());

        // Teraz ceny netto + 8% VAT: 49 → 52,92 EUR.
        $this->fund($this->customer, '100');
        [$service, $invoice] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'monthly', $this->config(), true);
        $this->assertSame(Money::parse('52.92'), $invoice->total);
        foreach ([
            route('panel.admin.billing'), route('panel.admin.billing.settings'), route('panel.admin.billing.catalog'),
            route('panel.admin.billing.products.create'), route('panel.admin.billing.products.edit', $product),
            route('panel.admin.billing.services'), route('panel.admin.billing.service', $service),
            route('panel.admin.billing.invoices'), route('panel.admin.billing.invoice', $invoice),
            route('panel.admin.billing.customers'), route('panel.admin.billing.customer', $this->customer),
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }

        $this->actingAs($this->admin)->post(route('panel.admin.billing.service.action', $service), ['action' => 'suspend'])->assertRedirect();
        $this->assertSame(BillingService::STATUS_SUSPENDED, $service->fresh()->status);
        $this->actingAs($this->admin)->post(route('panel.admin.billing.service.action', $service), ['action' => 'unsuspend'])->assertRedirect();
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->fresh()->status);
        $this->actingAs($this->admin)->put(route('panel.admin.billing.service.update', $service), ['name' => 'X', 'amount' => '39', 'next_due_at' => '2027-01-01T10:00'])->assertRedirect();
        $this->assertSame(Money::parse('39'), $service->fresh()->amount);
        $this->assertSame('2027-01-01 10:00', $service->fresh()->next_due_at->format('Y-m-d H:i'));
    }

    public function test_strony_klienta_renderuja_sie(): void
    {
        $this->fund($this->customer, '100');
        [$service] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'monthly', $this->config(), true);

        $this->actingAs($this->customer)->get(route('panel.billing'))->assertOk()->assertSee('51,00 PLN');
        $this->actingAs($this->customer)->get(route('panel.billing.wallet'))->assertOk()->assertSee('zapłata faktury');
        $this->actingAs($this->customer)->get(route('panel.billing.service', $service))->assertOk()->assertSee('Zrezygnuj z usługi');
        $this->actingAs($this->customer)->get(route('panel.dashboard'))->assertOk()->assertSee(route('panel.store'));
    }

    public function test_produkt_aplikacji_w_lokalizacji_przez_sklep(): void
    {
        $group = HypervisorGroup::query()->create(['name' => 'Frankfurt', 'is_public' => true, 'accepts_new_servers' => true]);
        $node = Hypervisor::factory()->create([
            'hypervisor_group_id' => $group->id, 'apps_enabled' => true, 'app_port_start' => 25565, 'app_port_end' => 25574,
            'ram_mb_total' => 16384, 'ram_mb_used' => 0, 'disk_gb_total' => 500, 'disk_gb_used' => 0,
            'last_health' => ['apps' => ['available' => true, 'docker_version' => '29.0'], 'public_ipv4' => '203.0.113.10'],
        ]);
        $plan = AppPlan::query()->create(['name' => 'Gra S', 'memory_mb' => 2048, 'cpu_percent' => 100, 'disk_mb' => 10240, 'ports' => 1]);
        app(EggImporter::class)->importBuiltin();
        $paper = AppEgg::query()->where('builtin_key', 'minecraft-paper')->firstOrFail();
        $product = Product::create([
            'product_category_id' => $this->product->product_category_id, 'name' => 'Minecraft 2 GB', 'type' => Product::TYPE_APP,
            'app_plan_id' => $plan->id, 'app_egg_ids' => [$paper->id], 'hypervisor_group_ids' => [$group->id],
        ]);
        $product->prices()->create(['cycle' => 'daily', 'amount' => Money::parse('1.20')]);
        $this->fund($this->customer, '10');

        $this->actingAs($this->customer)->get(route('panel.store.product', $product))->assertOk()->assertSee('Frankfurt')->assertSee($paper->displayName());
        // Lokalizacja wymagana (produkt ograniczony), szablon tylko z listy.
        $this->actingAs($this->customer)->post(route('panel.store.order', $product), ['cycle' => 'daily', 'egg' => $paper->id, 'name' => 'Survival', 'payment' => 'wallet', 'accept' => 1])
            ->assertSessionHasErrors('location');
        $response = $this->actingAs($this->customer)->post(route('panel.store.order', $product), [
            'cycle' => 'daily', 'location' => $group->id, 'egg' => $paper->id, 'name' => 'Survival', 'payment' => 'wallet', 'accept' => 1,
        ]);

        $service = BillingService::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->status, (string) $service->last_error);
        $response->assertRedirect(route('panel.apps.show', $service->appServer));
        $this->assertStringContainsString($service->appServer->uuid, $response->headers->get('Location'));
        $this->actingAs($this->customer)->get($response->headers->get('Location'))->assertOk();
        $this->assertSame($node->id, $service->appServer->hypervisor_id);
        $this->assertSame(Money::parse('8.80'), $this->customer->fresh()->wallet_balance, 'pierwsza doba z góry (ceny brutto)');
    }

    public function test_kategoria_ogranicza_dostepne_okresy(): void
    {
        $category = $this->product->category;
        $this->actingAs($this->admin)->put(route('panel.admin.billing.categories.update', $category), [
            'name' => 'VPS', 'is_active' => 1, 'cycles' => ['m', 'y'],
        ])->assertRedirect();
        $this->assertSame(['m', 'y'], $category->fresh()->allowed_cycles);
        $this->assertSame(['monthly'], array_keys($this->product->fresh()->load('prices', 'category')->priceMap()));

        $this->actingAs($this->customer)->get(route('panel.store.product', $this->product))->assertOk()->assertDontSee('value="hourly"', false);
        $this->actingAs($this->customer)->post(route('panel.store.order', $this->product), [
            'cycle' => 'hourly', 'template' => $this->template->id, 'hostname' => 'a.example.com', 'payment' => 'wallet', 'accept' => 1,
        ])->assertSessionHasErrors('cycle');
        try {
            app(ServiceManager::class)->checkout($this->customer, $this->product, 'hourly', $this->config(), true);
            $this->fail('Okres zablokowany w kategorii nie może być zamówiony');
        } catch (\DomainException) {
        }

        // Wszystkie zaznaczone albo żaden = bez ograniczeń.
        $this->actingAs($this->admin)->put(route('panel.admin.billing.categories.update', $category), ['name' => 'VPS', 'is_active' => 1])->assertRedirect();
        $this->assertNull($category->fresh()->allowed_cycles);
    }

    public function test_formularz_produktu_wybiera_okresy_i_ceny_zerowe(): void
    {
        $base = [
            'product_category_id' => $this->product->product_category_id, 'name' => 'Darmowy', 'type' => 'vps',
            'vps_package_id' => $this->product->vps_package_id, 'is_active' => 1, 'cycle_choice' => 1,
        ];
        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), $base + ['cycles' => []])->assertSessionHasErrors('prices');

        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), $base + [
            'cycles' => ['monthly', 'annually'], 'prices' => ['monthly' => '', 'annually' => '0', 'daily' => '5'], 'per_user_limit' => 1,
        ])->assertRedirect();
        $product = Product::query()->where('name', 'Darmowy')->firstOrFail();
        // Cena dzienna bez zaznaczenia nie trafia do oferty; puste pole = za darmo.
        $this->assertSame(['monthly' => 0, 'annually' => 0], $product->configuredPrices());
        $this->assertTrue($product->isFree());
        $this->assertSame(1, $product->per_user_limit);

        $this->product->category->update(['allowed_cycles' => ['hourly']]);
        $this->actingAs($this->admin)->put(route('panel.admin.billing.products.update', $product), $base + ['cycles' => ['monthly']])
            ->assertSessionHasErrors('prices');

        $this->actingAs($this->admin)->get(route('panel.admin.billing.products.edit', $product))->assertOk()->assertSee('kategoria nie dopuszcza');
        $this->actingAs($this->admin)->get(route('panel.admin.billing.catalog'))->assertOk()->assertSee('za darmo');
    }

    public function test_darmowy_produkt_miesieczny_startuje_bez_srodkow_i_odnawia_sie_sam(): void
    {
        $this->product->prices()->where('cycle', 'monthly')->update(['amount' => 0]);
        $this->product->update(['per_user_limit' => 1]);

        $this->actingAs($this->customer)->get(route('panel.store'))->assertOk()->assertSee('Za darmo');
        $this->actingAs($this->customer)->post(route('panel.store.order', $this->product), [
            'cycle' => 'monthly', 'template' => $this->template->id, 'hostname' => 'free.example.com', 'payment' => 'invoice', 'accept' => 1,
        ])->assertRedirect();

        $service = BillingService::query()->firstOrFail();
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->status);
        $this->assertNotNull($service->server_id);
        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame([Invoice::STATUS_PAID, 0], [$invoice->status, $invoice->total]);
        $this->assertSame('free', Payment::firstOrFail()->gateway);
        $this->assertSame(0, $this->customer->fresh()->wallet_balance);
        Mail::assertNotQueued(TemplatedMail::class, fn (TemplatedMail $m) => in_array($m->templateKey, ['invoice.created', 'invoice.paid'], true));

        // Limit jednej sztuki na klienta.
        $this->actingAs($this->customer)->post(route('panel.store.order', $this->product), [
            'cycle' => 'monthly', 'template' => $this->template->id, 'hostname' => 'free2.example.com', 'payment' => 'invoice', 'accept' => 1,
        ])->assertSessionHasErrors('payment');
        $this->assertSame(1, BillingService::query()->count());

        // Odnowienie: darmowa faktura rozliczona od razu, kolejny okres, bez zawieszenia.
        $due = $service->next_due_at->copy();
        $this->travelTo($due->copy()->subDays(3));
        $this->assertSame(1, app(ServiceManager::class)->createRenewals());
        $this->travelTo($due->copy()->addDays(10));
        app(ServiceManager::class)->processOverdue();
        $service->refresh();
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->status);
        $this->assertTrue($service->next_due_at->equalTo($due->copy()->addMonthNoOverflow()));
        $this->assertSame(0, Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
    }

    public function test_darmowa_usluga_godzinowa_nie_wymaga_salda(): void
    {
        Billing::save(['min_balance_metered' => '5']);
        $this->product->prices()->where('cycle', 'hourly')->update(['amount' => 0]);

        [$service] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'hourly', $this->config(), true);
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->status);

        $this->travel(5)->hours();
        $this->assertSame(5, app(ServiceManager::class)->chargeMetered());
        $this->assertSame(0, $this->customer->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::query()->count());
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertTrue($service->fresh()->next_due_at->isFuture());
    }

    public function test_edycja_produktu_z_przeniesieniem_ceny_na_istniejace_uslugi(): void
    {
        $this->fund($this->customer, '100');
        [$monthly] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'monthly', $this->config(), true);
        [$hourly] = app(ServiceManager::class)->checkout($this->customer, $this->product, 'hourly', $this->config('vps2.example.com'), true);
        $form = [
            'product_category_id' => $this->product->product_category_id, 'name' => 'VPS S', 'type' => 'vps',
            'vps_package_id' => $this->product->vps_package_id, 'is_active' => 1, 'cycle_choice' => 1,
            'cycles' => ['monthly'], 'prices' => ['monthly' => '59'],
        ];

        $this->actingAs($this->admin)->get(route('panel.admin.billing.catalog'))->assertOk()->assertSee(route('panel.admin.billing.products.edit', $this->product));
        $this->actingAs($this->admin)->get(route('panel.admin.billing.products.edit', $this->product))->assertOk()->assertSee('Zmień cenę istniejących usług');

        // Bez zaznaczenia — usługi zachowują cenę.
        $this->actingAs($this->admin)->put(route('panel.admin.billing.products.update', $this->product), $form)->assertRedirect();
        $this->assertSame(Money::parse('49'), $monthly->fresh()->amount);

        // Z zaznaczeniem — nowa cena; okres godzinowy wycofany, więc ta usługa zostaje przy starej.
        $this->actingAs($this->admin)->put(route('panel.admin.billing.products.update', $this->product), $form + ['apply_prices' => 1, 'apply_resources' => 1])
            ->assertRedirect(route('panel.admin.billing.products.edit', $this->product))->assertSessionHas('status');
        $this->assertSame(Money::parse('59'), $monthly->fresh()->amount);
        $this->assertSame(Money::parse('0.07'), $hourly->fresh()->amount);

        // Kolejna faktura odnowienia już z nową ceną.
        $this->travelTo($monthly->next_due_at->copy()->subDays(2));
        app(ServiceManager::class)->createRenewals();
        $this->assertSame(Money::parse('59'), Invoice::query()->latest('id')->first()->total);
    }

    public function test_edycja_produktu_aplikacji_ustawia_zasoby_planu(): void
    {
        Http::fake(['*' => Http::response(['restart_required' => false])]);
        $node = Hypervisor::factory()->create([
            'apps_enabled' => true, 'app_port_start' => 25565, 'app_port_end' => 25574, 'ram_mb_total' => 16384, 'ram_mb_used' => 0,
            'disk_gb_total' => 500, 'disk_gb_used' => 0, 'last_health' => ['apps' => ['available' => true, 'docker_version' => '29.0'], 'public_ipv4' => '203.0.113.10'],
        ]);
        $plan = AppPlan::query()->create(['name' => 'Gra S', 'memory_mb' => 2048, 'cpu_percent' => 100, 'disk_mb' => 10240, 'ports' => 1]);
        app(EggImporter::class)->importBuiltin();
        $egg = AppEgg::query()->where('builtin_key', 'minecraft-paper')->firstOrFail();
        $product = Product::create(['product_category_id' => $this->product->product_category_id, 'name' => 'MC', 'type' => Product::TYPE_APP, 'app_plan_id' => $plan->id]);
        $product->prices()->create(['cycle' => 'monthly', 'amount' => 0]);
        [$service] = app(ServiceManager::class)->checkout($this->customer, $product, 'monthly', ['egg_id' => $egg->id, 'name' => 'Survival', 'location_id' => null], true);
        $app = $service->appServer;
        $app->forceFill(['status' => AppServer::STATUS_READY])->save();

        $plan->update(['memory_mb' => 4096, 'disk_mb' => 20480]);
        $this->actingAs($this->admin)->put(route('panel.admin.billing.products.update', $product), [
            'product_category_id' => $product->product_category_id, 'name' => 'MC', 'type' => 'app', 'app_plan_id' => $plan->id,
            'is_active' => 1, 'cycle_choice' => 1, 'cycles' => ['monthly'], 'apply_resources' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([4096, 20480], [$app->fresh()->memory_mb, $app->fresh()->disk_mb]);
        $this->assertSame($node->id, $app->fresh()->hypervisor_id);
    }

    public function test_dowolne_okresy_kody_i_nazwy(): void
    {
        $this->assertSame('quarterly', Cycle::code(3, 'm'));
        $this->assertSame('annually', Cycle::code(12, 'm'));
        $this->assertSame('2y', Cycle::code(24, 'm'));
        $this->assertSame('3d', Cycle::code(3, 'd'));
        $this->assertSame([6, 'm'], Cycle::parse('semiannually'));
        $this->assertFalse(Cycle::valid('800h'));
        $this->assertFalse(Cycle::valid('0d'));
        $this->assertTrue(now()->addWeeks(2)->equalTo(Cycle::add(now(), '2w')));
        $this->assertSame(36, Cycle::hours('36h'));

        app()->setLocale('pl');
        $this->assertSame('3 dni', Cycle::duration('3d'));
        $this->assertSame('2 tygodnie', Cycle::duration('2w'));
        $this->assertSame('5 miesięcy', Cycle::duration('5m'));
        $this->assertSame('1 rok', Cycle::duration('annually'));
        $this->assertSame('/ 6 godzin', Cycle::per('6h'));
        app()->setLocale('en');
        $this->assertSame('3 days', Cycle::duration('3d'));
        $this->assertSame('1 week', Cycle::duration('1w'));
    }

    public function test_formularz_produktu_z_dowolnymi_okresami(): void
    {
        $base = [
            'product_category_id' => $this->product->product_category_id, 'name' => 'Elastyczny', 'type' => 'vps',
            'vps_package_id' => $this->product->vps_package_id, 'is_active' => 1, 'period_rows' => 1,
        ];
        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), $base)->assertSessionHasErrors('prices');
        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), $base + ['periods' => [
            ['count' => 3, 'unit' => 'd', 'price' => '5'], ['count' => 3, 'unit' => 'd', 'price' => '6'],
        ]])->assertSessionHasErrors('periods.1.count');
        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), $base + ['periods' => [
            ['count' => 400, 'unit' => 'd', 'price' => '5'],
        ]])->assertSessionHasErrors('periods.0.count');

        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), $base + ['periods' => [
            ['count' => 6, 'unit' => 'h', 'price' => '0,30'],
            ['count' => 2, 'unit' => 'w', 'price' => '15', 'once' => 1],
            ['count' => 1, 'unit' => 'm', 'price' => '29'],
            ['count' => 12, 'unit' => 'm', 'price' => ''],
        ]])->assertRedirect(route('panel.admin.billing.catalog'));
        $product = Product::query()->where('name', 'Elastyczny')->firstOrFail();
        $this->assertSame(['6h' => Money::parse('0.30'), '2w' => Money::parse('15'), 'monthly' => Money::parse('29'), 'annually' => 0], $product->configuredPrices());
        $this->assertFalse($product->renews('2w'));
        $this->assertTrue($product->renews('6h'));

        // Edycja zastępuje listę okresów.
        $this->actingAs($this->admin)->get(route('panel.admin.billing.products.edit', $product))->assertOk()->assertSee('periods[3][count]', false);
        $this->actingAs($this->admin)->put(route('panel.admin.billing.products.update', $product), $base + ['periods' => [
            ['count' => 7, 'unit' => 'd', 'price' => '10'],
        ]])->assertRedirect();
        $this->assertSame(['7d' => Money::parse('10')], $product->fresh()->load('prices')->configuredPrices());

        $this->actingAs($this->customer)->get(route('panel.store.product', $product))->assertOk()->assertSee('co 7 dni');
    }

    public function test_okres_szesciogodzinny_pobierany_z_portfela(): void
    {
        $this->product->prices()->create(['cycle' => '6h', 'amount' => Money::parse('0.30')]);
        $this->fund($this->customer, '10');

        [$service] = app(ServiceManager::class)->checkout($this->customer, $this->product, '6h', $this->config(), true);
        $this->assertTrue($service->metered());
        $this->assertSame(Money::parse('9.70'), $this->customer->fresh()->wallet_balance);
        $this->assertTrue($service->next_due_at->equalTo(now()->addHours(6)));

        $this->travel(7)->hours();
        $this->assertSame(1, app(ServiceManager::class)->chargeMetered());
        $this->assertSame(Money::parse('9.40'), $this->customer->fresh()->wallet_balance);
        $this->assertTrue($service->fresh()->next_due_at->equalTo(now()->subHours(7)->addHours(12)));
    }

    public function test_okres_jednorazowy_konczy_usluge_bez_odnowienia(): void
    {
        $this->product->prices()->create(['cycle' => '7d', 'amount' => Money::parse('10'), 'renews' => false]);
        $this->fund($this->customer, '10');

        $this->actingAs($this->customer)->get(route('panel.store.product', $this->product))->assertOk()->assertSee('jednorazowo na 7 dni', false);
        [$service, $invoice] = app(ServiceManager::class)->checkout($this->customer, $this->product, '7d', $this->config(), true);

        // Jednorazowy okres w dniach nie jest naliczany co dobę — płatność z góry fakturą/portfelem.
        $this->assertFalse($service->metered());
        $this->assertFalse($service->renews);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(Money::parse('10'), $invoice->total);
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->status);
        $this->assertTrue($service->cancel_at_period_end);
        $this->assertTrue($service->next_due_at->equalTo(now()->addDays(7)));
        $this->actingAs($this->customer)->get(route('panel.billing.service', $service))->assertOk()->assertSee('Usługa jednorazowa');

        $this->travelTo($service->next_due_at->copy()->subDays(2));
        $this->assertSame(0, app(ServiceManager::class)->createRenewals());
        $this->actingAs($this->admin)->post(route('panel.admin.billing.service.action', $service), ['action' => 'resume'])->assertSessionHasErrors('service');

        $this->travelTo($service->next_due_at->copy()->addMinute());
        $this->assertSame(1, app(ServiceManager::class)->endCancelled());
        $this->assertSame(BillingService::STATUS_TERMINATED, $service->fresh()->status);
    }

    public function test_odnowienie_okresu_dwutygodniowego(): void
    {
        $this->product->prices()->create(['cycle' => '2w', 'amount' => Money::parse('20')]);
        $this->fund($this->customer, '40');
        [$service] = app(ServiceManager::class)->checkout($this->customer, $this->product, '2w', $this->config(), true);
        $due = $service->next_due_at->copy();
        $this->assertTrue($due->equalTo(now()->addWeeks(2)));

        $this->travelTo($due->copy()->subDays(3));
        $this->assertSame(1, app(ServiceManager::class)->createRenewals());
        $this->assertTrue($service->fresh()->next_due_at->equalTo($due->copy()->addWeeks(2)), 'odnowienie opłacone z portfela');
    }

    public function test_harmonogram_nic_nie_robi_przy_wylaczonym_billingu(): void
    {
        Billing::save(['enabled' => false]);
        $this->artisan('virthub:billing')->assertSuccessful();
        Billing::save(['enabled' => true]);
        $this->artisan('virthub:billing')->assertSuccessful();
    }
}
