<?php

namespace Tests\Feature;

use App\Domain\Billing\Addons;
use App\Domain\Billing\Billing;
use App\Domain\Billing\Money;
use App\Domain\Billing\ServiceManager;
use App\Domain\Billing\Wallet;
use App\Domain\Metrics\Traffic;
use App\Domain\Provisioning\SelfService;
use App\Models\BillingService;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\PciAsset;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ResourcePack;
use App\Models\Server;
use App\Models\ServerTraffic;
use App\Models\User;
use App\Models\VpsPackage;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BillingAddonsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Hypervisor $node;

    private IpPool $pool;

    private VpsPackage $package;

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
        $this->node = Hypervisor::factory()->create(['cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 2000]);
        $this->pool = IpPool::factory()->create(['hypervisor_id' => $this->node->id]);
        IpAddress::factory()->count(8)->create(['ip_pool_id' => $this->pool->id, 'hypervisor_id' => $this->node->id]);
        $this->package = VpsPackage::factory()->create(['vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20, 'ip_count' => 1, 'ipv6_count' => 0]);
        $this->template = OsTemplate::factory()->create();

        Billing::save(['enabled' => true, 'currency' => 'PLN', 'tax_rate' => '0', 'prices_include_tax' => true]);
        $category = ProductCategory::create(['name' => 'VPS', 'slug' => 'vps']);
        $this->product = Product::create([
            'product_category_id' => $category->id, 'name' => 'VPS S', 'type' => Product::TYPE_VPS, 'vps_package_id' => $this->package->id,
            'addon_ip_hour' => Money::parse('0.01'), 'addon_pci_hour' => Money::parse('0.50'),
        ]);
        $this->product->prices()->createMany([
            ['cycle' => 'monthly', 'amount' => Money::parse('49')],
            ['cycle' => 'hourly', 'amount' => Money::parse('0.07')],
        ]);
    }

    private function fund(string $amount): void
    {
        app(Wallet::class)->adjust($this->customer, Money::parse($amount), 'test', $this->admin);
    }

    private function config(array $extra = []): array
    {
        return ['template_id' => $this->template->id, 'hostname' => 'vps1.example.com', 'ssh_keys' => [], 'location_id' => null, 'extra' => $extra];
    }

    public function test_dodatkowe_adresy_i_gpu_sa_doliczane_co_godzine(): void
    {
        $manager = app(ServiceManager::class);
        $this->fund('20');
        [$service] = $manager->checkout($this->customer, $this->product, 'hourly', $this->config(), true);
        $this->assertSame(BillingService::STATUS_ACTIVE, $service->status);
        $server = Server::findOrFail($service->server_id);
        $this->assertSame(['ips' => 0, 'pci' => 0], Addons::count($server));
        $balance = $this->customer->fresh()->wallet_balance;

        // Dwa adresy ponad pakiet i jedno GPU.
        IpAddress::query()->whereNull('server_id')->limit(2)->get()->each(fn (IpAddress $ip) => $ip->update(['server_id' => $server->id]));
        PciAsset::create(['hypervisor_id' => $this->node->id, 'name' => 'RTX', 'type' => 'gpu', 'pci_address' => '0000:01:00.0', 'server_id' => $server->id]);
        $this->assertSame(['ips' => 2, 'pci' => 1], Addons::count($server));
        $this->assertSame(Money::parse('0.52'), Addons::hourly($this->product, $server));

        $this->travel(2)->hours();
        $this->assertSame(2, $manager->chargeMetered());
        $this->assertSame($balance - 2 * Money::parse('0.59'), $this->customer->fresh()->wallet_balance);
        $last = WalletTransaction::query()->where('user_id', $this->customer->id)->latest('id')->first();
        $this->assertStringContainsString('2 × IP, 1 × PCI', $last->description);
    }

    public function test_bez_stawek_dodatki_nic_nie_kosztuja(): void
    {
        $this->product->update(['addon_ip_hour' => 0, 'addon_pci_hour' => 0]);
        $server = Server::factory()->create(['vps_package_id' => $this->package->id]);
        PciAsset::create(['hypervisor_id' => $this->node->id, 'name' => 'RTX', 'type' => 'gpu', 'pci_address' => '0000:02:00.0', 'server_id' => $server->id]);
        $this->assertSame(0, Addons::hourly($this->product->fresh(), $server));
    }

    public function test_klient_dobiera_zasoby_ponad_pakiet(): void
    {
        $this->product->update(['resource_options' => [
            'vcpu' => ['max' => 4, 'price_hour' => Money::parse('0.01')],
            'ram_gb' => ['max' => 8, 'price_hour' => Money::parse('0.005')],
        ]]);
        $product = $this->product->fresh();
        $this->assertSame(['vcpu', 'ram_gb'], array_keys($product->resourceOptions()));
        // Miesiąc = 730 h: 2 vCPU × 0,01 + 4 GB × 0,005 = 0,04 / h → 29,20.
        $this->assertSame(Money::parse('29.20'), $product->extrasPrice(['vcpu' => 2, 'ram_gb' => 4], 'monthly'));
        $this->assertSame(Money::parse('0.04'), $product->extrasPrice(['vcpu' => 2, 'ram_gb' => 4, 'disk_gb' => 50], 'hourly'), 'zasób spoza oferty jest pomijany');

        $this->actingAs($this->customer)->get(route('panel.store.product', $product))->assertOk()->assertSee('name="extra[vcpu]"', false)->assertDontSee('name="extra[disk_gb]"', false);
        $order = ['cycle' => 'monthly', 'template' => $this->template->id, 'hostname' => 'big.example.com', 'payment' => 'wallet', 'accept' => '1'];
        $this->post(route('panel.store.order', $product), $order + ['extra' => ['vcpu' => 5]])->assertSessionHasErrors('extra.vcpu');

        $this->fund('100');
        $this->post(route('panel.store.order', $product), $order + ['extra' => ['vcpu' => 2, 'ram_gb' => 4]])->assertSessionHasNoErrors();
        $service = BillingService::firstOrFail();
        $this->assertSame(Money::parse('49') + Money::parse('29.20'), $service->amount);
        $this->assertSame(['vcpu' => 2, 'ram_gb' => 4], $service->config['extra']);
        $server = Server::findOrFail($service->server_id);
        $this->assertSame([3, 5120, 20], [$server->vcpu, $server->ram_mb, $server->disk_gb]);
        $this->assertSame($this->package->id, $server->vps_package_id);
        $this->assertSame(1, $this->package->fresh()->vcpu, 'pakiet bazowy bez zmian');
    }

    public function test_administrator_ustawia_dodatki_i_zmienne_zasoby(): void
    {
        $this->actingAs($this->admin)->put(route('panel.admin.billing.products.update', $this->product), [
            'product_category_id' => $this->product->product_category_id, 'name' => 'VPS S', 'type' => 'vps', 'vps_package_id' => $this->package->id,
            'periods' => [['count' => 1, 'unit' => 'm', 'price' => '49']], 'is_active' => '1',
            'addon_ip_hour' => '0,02', 'addon_pci_hour' => '',
            'resource_options' => ['vcpu' => ['max' => '6', 'price' => '0,01'], 'ram_gb' => ['max' => '', 'price' => '1'], 'disk_gb' => ['max' => '100', 'price' => '']],
        ])->assertSessionHasNoErrors();
        $product = $this->product->fresh();
        $this->assertSame(Money::parse('0.02'), $product->addon_ip_hour);
        $this->assertSame(0, $product->addon_pci_hour);
        $this->assertSame(['vcpu' => ['max' => 6, 'price_hour' => Money::parse('0.01')], 'disk_gb' => ['max' => 100, 'price_hour' => 0]], $product->resource_options);
    }

    public function test_wspolny_transfer_pakietu_zasobow(): void
    {
        Billing::save(['enabled' => false]);
        $pack = ResourcePack::create(['user_id' => $this->customer->id, 'name' => 'Cloud', 'vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 200,
            'max_servers' => 3, 'vps_package_id' => $this->package->id, 'traffic_gb' => 10]);
        $a = Server::factory()->create(['user_id' => $this->customer->id, 'hypervisor_id' => $this->node->id, 'resource_pack_id' => $pack->id, 'bandwidth_gb' => 1000]);
        $b = Server::factory()->create(['user_id' => $this->customer->id, 'hypervisor_id' => $this->node->id, 'resource_pack_id' => $pack->id, 'bandwidth_gb' => 1000]);
        $period = Traffic::periodStart()->toDateString();
        ServerTraffic::create(['server_id' => $a->id, 'period_start' => $period, 'rx_bytes' => 3 * Traffic::GB, 'tx_bytes' => 3 * Traffic::GB]);
        ServerTraffic::create(['server_id' => $b->id, 'period_start' => $period, 'rx_bytes' => 2 * Traffic::GB, 'tx_bytes' => 1 * Traffic::GB]);

        $traffic = app(Traffic::class);
        $usage = $traffic->usage($a);
        $this->assertSame(9 * Traffic::GB, $usage['used']);
        $this->assertSame(10 * Traffic::GB, $usage['limit']);
        $this->assertSame(['pack' => 'Cloud', 'servers' => 2, 'own' => 6 * Traffic::GB], $usage['pool']);
        $this->assertFalse($traffic->enforce($a));

        // Przekroczenie wspólnego limitu blokuje wszystkie maszyny pakietu.
        ServerTraffic::query()->where('server_id', $b->id)->update(['tx_bytes' => 3 * Traffic::GB]);
        $this->assertTrue($traffic->enforce($b));
        $this->assertNotNull($a->fresh()->traffic_blocked_at);
        $this->assertNotNull($b->fresh()->traffic_blocked_at);

        // Dokupiony transfer na jednej maszynie powiększa pulę — obie wracają.
        $traffic->addBlock($a->fresh(), 5);
        $this->assertNull($a->fresh()->traffic_blocked_at);
        $this->assertSame(0, $traffic->releaseEligible());
        $this->assertNull($b->fresh()->traffic_blocked_at);
    }

    public function test_pakiet_zasobow_liczy_dodatkowe_adresy(): void
    {
        $pack = ResourcePack::create(['user_id' => $this->customer->id, 'name' => 'Cloud', 'vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 200,
            'max_servers' => 3, 'vps_package_id' => $this->package->id, 'price_vcpu_hour' => 100, 'price_ip_hour' => 30]);
        $server = Server::factory()->create(['user_id' => $this->customer->id, 'resource_pack_id' => $pack->id, 'vps_package_id' => $this->package->id, 'vcpu' => 2, 'ram_mb' => 1024, 'disk_gb' => 10]);
        IpAddress::query()->whereNull('server_id')->limit(3)->get()->each(fn (IpAddress $ip) => $ip->update(['server_id' => $server->id]));
        $this->assertSame(200 + 2 * 30, $pack->serverHourlyCost($server));

        $this->customer->forceFill(['wallet_balance' => 10000])->save();
        $selfService = app(SelfService::class);
        $selfService->billPack($pack->fresh());
        $this->travel(1)->hours();
        $this->assertSame(1, $selfService->billPack($pack->fresh()));
        $this->assertSame(10000 - 260, $this->customer->fresh()->wallet_balance);
    }
}
