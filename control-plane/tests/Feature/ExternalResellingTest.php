<?php

namespace Tests\Feature;

use App\Domain\Billing\Billing;
use App\Domain\Billing\Money;
use App\Domain\Billing\ServiceManager;
use App\Domain\Billing\Wallet;
use App\Domain\External\ExternalServerManager;
use App\Domain\External\ProviderRegistry;
use App\Models\BillingService;
use App\Models\ExternalServer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProviderAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Reselling: produkt „VPS u dostawcy”, zamówienie przez billing i zarządzanie maszyną — na atrapie sterownika. */
class ExternalResellingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private ProviderAccount $account;

    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Wszystkie akcje w jednej minucie — limity zapytań nie są tu przedmiotem testu.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        FakeProviderDriver::reset();
        app(ProviderRegistry::class)->register('fake', 'Fake Cloud', FakeProviderDriver::class);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->customer = User::factory()->create();
        Billing::save(['enabled' => true, 'currency' => 'PLN', 'tax_rate' => '23', 'prices_include_tax' => true]);
        $this->category = ProductCategory::create(['name' => 'Chmura', 'slug' => 'chmura']);
        $this->account = new ProviderAccount(['driver' => 'fake', 'name' => 'Fake — główne']);
        $this->account->setCredentials(['token' => 'good-token']);
        $this->account->save();
    }

    public function test_administrator_dodaje_konto_i_produkt_z_katalogu_dostawcy(): void
    {
        $this->actingAs($this->admin)->post(route('panel.admin.providers.store'), ['driver' => 'fake', 'name' => 'Złe', 'credentials' => ['token' => 'bad']])
            ->assertSessionHasErrors('account');
        $this->actingAs($this->admin)->post(route('panel.admin.providers.store'), ['driver' => 'fake', 'name' => 'Drugie', 'credentials' => ['token' => 'good-token']])
            ->assertSessionHasNoErrors();
        $this->assertSame('good-token', ProviderAccount::query()->where('name', 'Drugie')->sole()->credentials()['token']);
        $this->assertStringNotContainsString('good-token', (string) ProviderAccount::query()->where('name', 'Drugie')->sole()->getAttributes()['credentials']);

        $this->actingAs($this->admin)->getJson(route('panel.admin.providers.catalog', $this->account))->assertOk()->assertJsonPath('plans.0.id', 'std');

        $base = ['product_category_id' => $this->category->id, 'name' => 'Cloud S', 'type' => 'external', 'provider_account_id' => $this->account->id,
            'ext_location' => 'waw', 'ext_plan' => 'std', 'ext_cpu' => 2, 'ext_ram_mb' => 2048, 'ext_disk_gb' => 40, 'ext_images' => ['11', '12'],
            'period_rows' => 1, 'periods' => [['count' => 1, 'unit' => 'm', 'price' => '39']], 'is_active' => 1];
        // Ponad limit typu i typ niedostępny w lokalizacji.
        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), ['ext_cpu' => 64] + $base)->assertSessionHasErrors('ext_cpu');
        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), ['ext_plan' => 'fixed-1'] + $base)->assertSessionHasErrors('ext_plan');

        $this->actingAs($this->admin)->post(route('panel.admin.billing.products.store'), $base)->assertSessionHasNoErrors();
        $product = Product::query()->where('name', 'Cloud S')->sole();
        $this->assertSame(['location' => 'waw', 'plan' => 'std', 'cpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 40,
            'images' => [['id' => '11', 'name' => 'Debian 12'], ['id' => '12', 'name' => 'Ubuntu 24.04']]], $product->external_config);
        $this->assertTrue($product->deliverable());

        // Klient widzi go w sklepie jak zwykły VPS (bez nazwy dostawcy).
        $this->actingAs($this->customer)->get(route('panel.store.product', $product))->assertOk()
            ->assertSee('Ubuntu 24.04')->assertSee('2 vCPU')->assertDontSee('Fake Cloud');
    }

    private function product(): Product
    {
        $product = Product::create([
            'product_category_id' => $this->category->id, 'name' => 'Cloud S', 'type' => Product::TYPE_EXTERNAL,
            'provider_account_id' => $this->account->id,
            'external_config' => ['location' => 'syd', 'plan' => 'fixed-1', 'cpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 25, 'images' => [['id' => '11', 'name' => 'Debian 12']]],
        ]);
        $product->prices()->create(['cycle' => 'monthly', 'amount' => Money::parse('39')]);

        return $product;
    }

    public function test_zamowienie_tworzy_maszyne_u_dostawcy_i_klient_nia_zarzadza(): void
    {
        $product = $this->product();
        app(Wallet::class)->adjust($this->customer, Money::parse('100'), 'test', $this->admin);

        $this->actingAs($this->customer)->post(route('panel.store.order', $product), [
            'cycle' => 'monthly', 'image' => '11', 'hostname' => 'web1.example.com', 'payment' => 'wallet', 'accept' => 1,
        ])->assertSessionHasNoErrors();

        $service = BillingService::query()->sole();
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->status);
        $server = ExternalServer::query()->sole();
        $this->assertSame($server->id, $service->external_server_id);
        $this->assertSame('vm-1', $server->remote_id); // kolejka sync: job wysłał zamówienie
        $this->assertSame(['create:fixed-1:syd:11:1/1024/25'], FakeProviderDriver::$calls);
        $this->assertSame(ExternalServer::BUILDING, $server->status);

        // Harmonogram: dostawca skończył — IP i hasło w panelu.
        FakeProviderDriver::finish('vm-1');
        app(ExternalServerManager::class)->syncTransitional();
        $server->refresh();
        $this->assertSame(ExternalServer::RUNNING, $server->status);
        $this->assertSame('203.0.113.50', $server->ipv4);
        $this->assertSame('Root-Pass-1', $server->password);

        $this->actingAs($this->customer)->get(route('panel.dashboard'))->assertOk()->assertSee('web1.example.com')->assertSee('203.0.113.50');
        $this->actingAs($this->customer)->get(route('panel.cloud.show', $server))->assertOk()
            ->assertSee('203.0.113.50')->assertSee('Root-Pass-1')->assertDontSee('Fake');

        $this->actingAs($this->customer)->post(route('panel.cloud.power', $server), ['action' => 'stop'])->assertSessionHasNoErrors();
        $this->actingAs($this->customer)->post(route('panel.cloud.console', $server))->assertRedirect('https://console.provider.test/vnc/vm-1?token=abc');
        $this->actingAs($this->customer)->post(route('panel.cloud.rdns', $server), ['ip' => '203.0.113.50', 'hostname' => 'web1.example.com'])->assertSessionHasNoErrors();
        $this->actingAs($this->customer)->post(route('panel.cloud.rdns', $server), ['ip' => '198.51.100.1', 'hostname' => 'x.example.com'])->assertSessionHasErrors('server');
        $this->actingAs($this->customer)->post(route('panel.cloud.reinstall', $server), ['image' => '11', 'confirm' => 'zle'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->customer)->post(route('panel.cloud.reinstall', $server), ['image' => '11', 'confirm' => 'web1.example.com'])->assertSessionHasNoErrors();
        $this->assertContains('power:vm-1:stop', FakeProviderDriver::$calls);
        $this->assertContains('rdns:vm-1:203.0.113.50:web1.example.com', FakeProviderDriver::$calls);
        $this->assertContains('reinstall:vm-1:11', FakeProviderDriver::$calls);

        // Cudzy klient — brak dostępu.
        $this->actingAs(User::factory()->create())->get(route('panel.cloud.show', $server))->assertForbidden();

        // Billing: zawieszenie zatrzymuje maszynę i blokuje akcje, wznowienie ją uruchamia, usunięcie kasuje u dostawcy.
        $services = app(ServiceManager::class);
        $services->suspend($service->fresh(), 'unpaid');
        $this->assertTrue($server->fresh()->isSuspended());
        $this->actingAs($this->customer)->post(route('panel.cloud.power', $server), ['action' => 'start'])->assertSessionHasErrors('server');
        $services->unsuspend($service->fresh());
        $this->assertFalse($server->fresh()->isSuspended());
        $this->assertSame('power:vm-1:start', end(FakeProviderDriver::$calls));

        $this->assertTrue($services->terminate($service->fresh(), 'admin'));
        $this->assertContains('destroy:vm-1', FakeProviderDriver::$calls);
        $this->assertSoftDeleted($server);
        $this->assertSame(BillingService::STATUS_TERMINATED, $service->fresh()->status);
    }

    public function test_blad_dostawcy_przy_tworzeniu_i_brak_sterownika(): void
    {
        $product = $this->product();
        FakeProviderDriver::$failCreate = true;
        app(Wallet::class)->adjust($this->customer, Money::parse('100'), 'test', $this->admin);
        app(ServiceManager::class)->checkout($this->customer, $product, 'monthly', ['image' => '11', 'hostname' => 'a.example.com', 'ssh_keys' => []], true);

        $server = ExternalServer::query()->sole();
        $this->assertSame(ExternalServer::ERROR, $server->status);
        $this->assertStringContainsString('402', (string) $server->last_error);

        // Addon wyłączony → produkt znika ze sklepu.
        $this->account->update(['driver' => 'missing']);
        $this->assertFalse($product->fresh()->deliverable());
    }
}
