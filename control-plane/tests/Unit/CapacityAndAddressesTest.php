<?php

namespace Tests\Unit;

use App\Domain\Provisioning\HypervisorSelector;
use App\Domain\Provisioning\IpAllocator;
use App\Domain\Provisioning\NoAddressesException;
use App\Domain\Provisioning\NoCapacityException;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapacityAndAddressesTest extends TestCase
{
    use RefreshDatabase;

    private HypervisorSelector $selector;

    private IpAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->selector = app(HypervisorSelector::class);
        $this->allocator = app(IpAllocator::class);
    }

    // --- dobór węzła --------------------------------------------------------

    public function test_wybiera_najbardziej_zapelniony_wezel_ktory_jeszcze_miesci(): void
    {
        // Bin-packing: dopełniamy zajęty węzeł, zamiast rozsypywać małe maszyny
        // po całej flocie i tracić miejsce na duże zamówienia.
        $pusty = Hypervisor::factory()->create([
            'name' => 'pusty', 'cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 1000,
        ]);
        $zajety = Hypervisor::factory()->create([
            'name' => 'zajety', 'cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 1000,
            'cpu_cores_used' => 24, 'ram_mb_used' => 49152, 'disk_gb_used' => 700,
        ]);

        $wybrany = $this->selector->select(vcpu: 2, ramMb: 4096, diskGb: 50);

        $this->assertSame($zajety->id, $wybrany->id);
        $this->assertNotSame($pusty->id, $wybrany->id);
    }

    public function test_pomija_wezly_offline_i_wylaczone_ze_sprzedazy(): void
    {
        Hypervisor::factory()->offline()->create();
        Hypervisor::factory()->create(['accepts_new_servers' => false]);

        $this->assertNull($this->selector->select(1, 1024, 10));
    }

    public function test_pomija_wezel_bez_swiezego_heartbeatu(): void
    {
        // Status w bazie mówi „online", ale ostatni kontakt był dawno —
        // wysłanie zamówienia na taki węzeł skończyłoby się zawieszoną maszyną.
        Hypervisor::factory()->create([
            'status' => Hypervisor::STATUS_ONLINE,
            'last_seen_at' => now()->subMinutes(30),
        ]);

        $this->assertNull($this->selector->select(1, 1024, 10));
    }

    public function test_rezerwacja_odmawia_gdy_zabraknie_ktoregokolwiek_zasobu(): void
    {
        Hypervisor::factory()->create([
            'cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 100,
            'disk_gb_used' => 80, // wolne tylko 20 GB
        ]);

        // hypervisor_id = null jawnie, żeby fabryka nie utworzyła sobie
        // drugiego, pustego węzła i nie unieważniła całego testu.
        $server = Server::factory()->create([
            'hypervisor_id' => null,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 50,
        ]);

        $this->expectException(NoCapacityException::class);
        $this->selector->reserve($server);
    }

    public function test_zwolnienie_nie_schodzi_ponizej_zera(): void
    {
        $hypervisor = Hypervisor::factory()->create([
            'cpu_cores_used' => 2, 'ram_mb_used' => 4096, 'disk_gb_used' => 50,
        ]);
        $server = Server::factory()->create([
            'hypervisor_id' => $hypervisor->id,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 50,
        ]);

        // Ponowione zadanie może zwolnić zasoby dwa razy — księgowanie musi to
        // wytrzymać, inaczej węzeł „zyskuje" nieistniejącą pojemność.
        $this->selector->release($server);
        $this->selector->release($server);

        $hypervisor->refresh();
        $this->assertSame(0, $hypervisor->cpu_cores_used);
        $this->assertSame(0, $hypervisor->ram_mb_used);
        $this->assertSame(0, $hypervisor->disk_gb_used);
    }

    public function test_przeliczenie_naprawia_rozjechane_liczniki(): void
    {
        $hypervisor = Hypervisor::factory()->create([
            'cpu_cores_used' => 999, 'ram_mb_used' => 999999, 'disk_gb_used' => 9999,
        ]);
        Server::factory()->count(2)->create([
            'hypervisor_id' => $hypervisor->id,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 50,
        ]);

        $this->selector->recalculate($hypervisor);

        $hypervisor->refresh();
        $this->assertSame(4, $hypervisor->cpu_cores_used);
        $this->assertSame(8192, $hypervisor->ram_mb_used);
        $this->assertSame(100, $hypervisor->disk_gb_used);
    }

    public function test_zajetosc_liczy_najbardziej_obciazony_wymiar(): void
    {
        $hypervisor = Hypervisor::factory()->create([
            'cpu_cores_total' => 100, 'cpu_cores_used' => 10,   // 10%
            'ram_mb_total' => 1000, 'ram_mb_used' => 900,       // 90%
            'disk_gb_total' => 100, 'disk_gb_used' => 50,       // 50%
        ]);

        $this->assertSame(90, $hypervisor->utilisationPercent());
    }

    // --- adresy -------------------------------------------------------------

    public function test_pierwszy_przydzielony_adres_zostaje_glownym(): void
    {
        $hypervisor = Hypervisor::factory()->create();
        $pool = IpPool::factory()->create(['hypervisor_id' => $hypervisor->id]);
        IpAddress::factory()->count(3)->create([
            'ip_pool_id' => $pool->id, 'hypervisor_id' => $hypervisor->id,
        ]);
        $server = Server::factory()->create(['hypervisor_id' => $hypervisor->id]);

        $addresses = $this->allocator->allocate($server, $hypervisor, 2);

        $this->assertCount(2, $addresses);
        $this->assertTrue($addresses->first()->fresh()->is_primary);
        $this->assertFalse($addresses->last()->fresh()->is_primary);
    }

    public function test_nie_przydziela_adresow_zarezerwowanych(): void
    {
        $hypervisor = Hypervisor::factory()->create();
        $pool = IpPool::factory()->create(['hypervisor_id' => $hypervisor->id]);
        IpAddress::factory()->count(2)->create([
            'ip_pool_id' => $pool->id, 'hypervisor_id' => $hypervisor->id, 'is_reserved' => true,
        ]);
        $server = Server::factory()->create(['hypervisor_id' => $hypervisor->id]);

        $this->expectException(NoAddressesException::class);
        $this->allocator->allocate($server, $hypervisor, 1);
    }

    public function test_zwolniony_adres_traci_rdns_poprzedniego_wlasciciela(): void
    {
        $hypervisor = Hypervisor::factory()->create();
        $pool = IpPool::factory()->create(['hypervisor_id' => $hypervisor->id]);
        $server = Server::factory()->create(['hypervisor_id' => $hypervisor->id]);
        $address = IpAddress::factory()->create([
            'ip_pool_id' => $pool->id,
            'hypervisor_id' => $hypervisor->id,
            'server_id' => $server->id,
            'is_primary' => true,
            'rdns' => 'stary-klient.example.com',
        ]);

        $this->allocator->releaseAll($server);

        $address->refresh();
        $this->assertNull($address->server_id);
        $this->assertNull($address->rdns, 'rDNS poprzedniego klienta nie może zostać na adresie');
        $this->assertFalse($address->is_primary);
    }

    public function test_import_puli_rozwija_zakres_i_rezerwuje_brame(): void
    {
        $hypervisor = Hypervisor::factory()->create();
        $pool = IpPool::factory()->create([
            'hypervisor_id' => $hypervisor->id,
            'cidr' => '203.0.113.0/24',
            'gateway' => '203.0.113.1',
        ]);

        $imported = $this->allocator->importPool($pool, '203.0.113.1', '203.0.113.10');

        $this->assertSame(10, $imported);
        $this->assertTrue(IpAddress::where('address', '203.0.113.1')->first()->is_reserved,
            'Brama nie może trafić do klienta');
        $this->assertSame(9, IpAddress::assignable()->count());
    }

    public function test_powtorny_import_nie_duplikuje_adresow(): void
    {
        $hypervisor = Hypervisor::factory()->create();
        $pool = IpPool::factory()->create(['hypervisor_id' => $hypervisor->id]);

        $this->allocator->importPool($pool, '203.0.113.10', '203.0.113.20');
        $this->allocator->importPool($pool, '203.0.113.10', '203.0.113.25');

        $this->assertSame(16, IpAddress::count());
    }
}
