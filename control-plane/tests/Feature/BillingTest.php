<?php

namespace Tests\Feature;

use App\Domain\Apps\EggImporter;
use App\Domain\Billing\Billing;
use App\Domain\Billing\InsufficientFunds;
use App\Domain\Billing\InvoiceManager;
use App\Domain\Billing\Money;
use App\Domain\Billing\ServiceManager;
use App\Domain\Billing\Wallet;
use App\Enums\ServerState;
use App\Mail\TemplatedMail;
use App\Models\AppEgg;
use App\Models\AppPlan;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $response->assertRedirect(route('panel.apps.show', $service->app_server_id));
        $this->assertSame($node->id, $service->appServer->hypervisor_id);
        $this->assertSame(Money::parse('8.80'), $this->customer->fresh()->wallet_balance, 'pierwsza doba z góry (ceny brutto)');
    }

    public function test_harmonogram_nic_nie_robi_przy_wylaczonym_billingu(): void
    {
        Billing::save(['enabled' => false]);
        $this->artisan('virthub:billing')->assertSuccessful();
        Billing::save(['enabled' => true]);
        $this->artisan('virthub:billing')->assertSuccessful();
    }
}
