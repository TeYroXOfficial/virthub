<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Domain\Network\HypervisorGroupManager;
use App\Domain\Network\IpPoolManager;
use App\Domain\Provisioning\IpAllocator;
use App\Domain\Provisioning\NoAddressesException;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Pule adresów: zasięg węzeł/grupa, NAT, IPv6 i walidacja sieciowa.
 */
class NetworkPoolsTest extends TestCase
{
    use RefreshDatabase;

    private IpAllocator $allocator;

    private IpPoolManager $pools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = app(IpAllocator::class);
        $this->pools = app(IpPoolManager::class);
    }

    /** @param  array<string, mixed>  $overrides */
    private function createPool(array $overrides): IpPool
    {
        return $this->pools->create([
            'name' => 'Pula',
            'prefix' => 24,
            ...$overrides,
        ])['pool'];
    }

    private function serverOn(Hypervisor $node): Server
    {
        return Server::factory()->create(['hypervisor_id' => $node->id]);
    }

    // --- zasięg: węzeł i grupa ----------------------------------------------

    public function test_pula_grupy_obsluguje_kazdy_wezel_grupy(): void
    {
        $group = HypervisorGroup::factory()->create();
        $a = Hypervisor::factory()->create(['hypervisor_group_id' => $group->id]);
        $b = Hypervisor::factory()->create(['hypervisor_group_id' => $group->id]);
        $outside = Hypervisor::factory()->create();

        $this->createPool([
            'scope' => 'group', 'hypervisor_group_id' => $group->id,
            'cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1',
            'range_from' => '203.0.113.10', 'range_to' => '203.0.113.11',
        ]);

        $this->assertSame('203.0.113.10', $this->allocator->allocate($this->serverOn($a), $a)->first()->address);
        $this->assertSame('203.0.113.11', $this->allocator->allocate($this->serverOn($b), $b)->first()->address);

        $this->expectException(NoAddressesException::class);
        $this->allocator->allocate($this->serverOn($outside), $outside);
    }

    public function test_pula_wezla_ma_pierwszenstwo_przed_pula_grupy(): void
    {
        // Pula grupy jest wspólna — nie może się kończyć przez węzeł, który
        // ma własne adresy.
        $group = HypervisorGroup::factory()->create();
        $node = Hypervisor::factory()->create(['hypervisor_group_id' => $group->id]);

        $this->createPool([
            'scope' => 'group', 'hypervisor_group_id' => $group->id,
            'cidr' => '198.51.100.0/24', 'gateway' => '198.51.100.1',
            'range_from' => '198.51.100.10', 'range_to' => '198.51.100.20',
        ]);
        $this->createPool([
            'hypervisor_id' => $node->id,
            'cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1',
            'range_from' => '203.0.113.10', 'range_to' => '203.0.113.20',
        ]);

        $address = $this->allocator->allocate($this->serverOn($node), $node)->first();

        $this->assertStringStartsWith('203.0.113.', $address->address);
    }

    public function test_adres_puli_grupy_wiaze_sie_z_wezlem_tylko_na_czas_przydzialu(): void
    {
        $group = HypervisorGroup::factory()->create();
        $node = Hypervisor::factory()->create(['hypervisor_group_id' => $group->id]);
        $this->createPool([
            'scope' => 'group', 'hypervisor_group_id' => $group->id,
            'cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1',
            'range_from' => '203.0.113.10', 'range_to' => '203.0.113.10',
        ]);
        $server = $this->serverOn($node);

        $address = $this->allocator->allocate($server, $node)->first();
        $this->assertSame($node->id, $address->fresh()->hypervisor_id);

        $this->allocator->releaseAll($server);
        $this->assertNull($address->fresh()->hypervisor_id, 'Zwolniony adres wraca do całej grupy');
    }

    public function test_wezel_nie_opuszcza_grupy_z_uzywanymi_adresami(): void
    {
        $group = HypervisorGroup::factory()->create();
        $node = Hypervisor::factory()->create(['hypervisor_group_id' => $group->id]);
        $this->createPool([
            'scope' => 'group', 'hypervisor_group_id' => $group->id,
            'cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1',
            'range_from' => '203.0.113.10', 'range_to' => '203.0.113.10',
        ]);
        $this->allocator->allocate($this->serverOn($node), $node);

        $this->expectException(ValidationException::class);
        app(HypervisorGroupManager::class)->assign($node, null);
    }

    // --- NAT ----------------------------------------------------------------

    public function test_pakiet_publiczny_nie_dostaje_adresow_nat(): void
    {
        $node = Hypervisor::factory()->create();
        $this->createPool([
            'hypervisor_id' => $node->id, 'type' => 'nat',
            'cidr' => '10.10.0.0/24', 'gateway' => '10.10.0.1',
        ]);

        $this->assertFalse($this->allocator->hasCapacity($node, IpPool::TYPE_PUBLIC, 1));
        $this->assertTrue($this->allocator->hasCapacity($node, IpPool::TYPE_NAT, 1));

        $this->expectException(NoAddressesException::class);
        $this->allocator->allocate($this->serverOn($node), $node, 1, 0, IpPool::TYPE_PUBLIC);
    }

    public function test_ta_sama_siec_nat_moze_stac_na_kazdym_wezle(): void
    {
        // Unikalność adresu prywatnego obowiązuje w puli, nie globalnie.
        $a = Hypervisor::factory()->create();
        $b = Hypervisor::factory()->create();

        foreach ([$a, $b] as $node) {
            $this->createPool([
                'hypervisor_id' => $node->id, 'type' => 'nat',
                'cidr' => '10.10.0.0/24', 'gateway' => '10.10.0.1',
            ]);
        }

        $this->assertSame(2, IpAddress::where('address', '10.10.0.5')->count());
    }

    public function test_publiczna_pula_nie_moze_nachodzic_na_inna(): void
    {
        $this->createPool([
            'hypervisor_id' => Hypervisor::factory()->create()->id,
            'cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1',
        ]);

        $this->expectException(ValidationException::class);
        $this->createPool([
            'hypervisor_id' => Hypervisor::factory()->create()->id,
            'cidr' => '203.0.113.128/25', 'gateway' => '203.0.113.129',
        ]);
    }

    public function test_nat_przyjmuje_tylko_sieci_prywatne(): void
    {
        $this->expectException(ValidationException::class);
        $this->createPool([
            'hypervisor_id' => Hypervisor::factory()->create()->id, 'type' => 'nat',
            'cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1',
        ]);
    }

    public function test_brama_nat_musi_lezec_w_sieci(): void
    {
        $this->expectException(ValidationException::class);
        $this->createPool([
            'hypervisor_id' => Hypervisor::factory()->create()->id, 'type' => 'nat',
            'cidr' => '10.10.0.0/24', 'gateway' => '10.20.0.1',
        ]);
    }

    public function test_porty_wynikaja_z_pozycji_adresu(): void
    {
        $pool = $this->createPool([
            'hypervisor_id' => Hypervisor::factory()->create()->id, 'type' => 'nat',
            'cidr' => '10.10.0.0/24', 'gateway' => '10.10.0.1',
            'nat_port_start' => 10000, 'nat_ports_per_server' => 20,
        ]);

        $this->assertSame(['from' => 10100, 'to' => 10119], $pool->natPortsFor('10.10.0.5'));
        // Cała pula: od .1 (10020–10039) do .254 (15080–15099).
        $this->assertSame(['from' => 10020, 'to' => 15099], $pool->natPortSpan());
        $this->assertSame(10100, IpAddress::where('address', '10.10.0.5')->first()->natPorts()['ssh']);
    }

    public function test_bloki_portow_musza_zmiescic_sie_ponizej_65535(): void
    {
        $this->expectException(ValidationException::class);
        $this->createPool([
            'hypervisor_id' => Hypervisor::factory()->create()->id, 'type' => 'nat',
            'cidr' => '10.10.0.0/24', 'gateway' => '10.10.0.1',
            'nat_port_start' => 60000, 'nat_ports_per_server' => 100,
        ]);
    }

    public function test_porty_pul_nat_nie_moga_kolidowac_na_wspolnym_wezle(): void
    {
        $group = HypervisorGroup::factory()->create();
        $node = Hypervisor::factory()->create(['hypervisor_group_id' => $group->id]);
        $this->createPool([
            'scope' => 'group', 'hypervisor_group_id' => $group->id, 'type' => 'nat',
            'cidr' => '10.10.0.0/24', 'gateway' => '10.10.0.1',
            'nat_port_start' => 10000, 'nat_ports_per_server' => 20,
        ]);

        try {
            // Węzeł należy do grupy — jego własna pula NAT trafi na ten sam
            // węzeł co pula grupy, więc porty nie mogą się pokrywać.
            $this->createPool([
                'hypervisor_id' => $node->id, 'type' => 'nat',
                'cidr' => '10.20.0.0/24', 'gateway' => '10.20.0.1',
                'nat_port_start' => 12000, 'nat_ports_per_server' => 20,
            ]);
            $this->fail('Przyjęto kolidujący zakres portów');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nat_port_start', $e->errors());
        }

        // Węzeł spoza grupy ma własny stos NAT — ten sam zakres jest dozwolony.
        $this->createPool([
            'hypervisor_id' => Hypervisor::factory()->create()->id, 'type' => 'nat',
            'cidr' => '10.20.0.0/24', 'gateway' => '10.20.0.1',
            'nat_port_start' => 12000, 'nat_ports_per_server' => 20,
        ]);
        $this->assertSame(2, IpPool::count());
    }

    public function test_ladunek_agenta_opisuje_nat(): void
    {
        $node = Hypervisor::factory()->create();
        $this->createPool([
            'hypervisor_id' => $node->id, 'type' => 'nat',
            'cidr' => '10.10.0.0/24', 'gateway' => '10.10.0.1',
            'range_from' => '10.10.0.5', 'range_to' => '10.10.0.5',
            'nat_public_address' => '198.51.100.7',
            'nat_port_start' => 10000, 'nat_ports_per_server' => 20,
        ]);
        $server = $this->serverOn($node);
        $this->allocator->allocate($server, $node, 1, 0, IpPool::TYPE_NAT);

        $this->assertSame([[
            'address' => '10.10.0.5',
            'prefix' => 24,
            'gateway' => '10.10.0.1',
            'version' => 4,
            'mode' => 'nat',
            'nat' => [
                'network' => '10.10.0.0/24',
                'snat_address' => '198.51.100.7',
                'port_from' => 10100,
                'port_to' => 10119,
            ],
        ]], ServerPayload::interfaces($server));
    }

    // --- IPv6 ---------------------------------------------------------------

    public function test_ipv6_przydzielane_kolejno_z_pominieciem_bramy(): void
    {
        $node = Hypervisor::factory()->create();
        $pool = $this->createPool([
            'hypervisor_id' => $node->id,
            'cidr' => '2001:db8:10::/64', 'gateway' => '2001:db8:10::1', 'prefix' => 64,
        ]);

        $this->assertSame(0, $pool->addresses()->count(), 'Pula IPv6 nie jest rozwijana przy imporcie');

        $first = $this->allocator->allocate($this->serverOn($node), $node, 0, 2);
        $second = $this->allocator->allocate($this->serverOn($node), $node, 0, 1);

        $this->assertSame(['2001:db8:10::2', '2001:db8:10::3'], $first->pluck('address')->all());
        $this->assertSame('2001:db8:10::4', $second->first()->address);
        $this->assertSame(6, $second->first()->version);
    }

    public function test_zwolniony_adres_ipv6_wraca_do_obiegu(): void
    {
        $node = Hypervisor::factory()->create();
        $this->createPool([
            'hypervisor_id' => $node->id,
            'cidr' => '2001:db8:10::/64', 'gateway' => '2001:db8:10::1', 'prefix' => 64,
            'range_from' => '2001:db8:10::100', 'range_to' => '2001:db8:10::100',
        ]);

        $server = $this->serverOn($node);
        $this->allocator->allocate($server, $node, 0, 1);
        $this->allocator->releaseAll($server);

        $again = $this->allocator->allocate($this->serverOn($node), $node, 0, 1);
        $this->assertSame('2001:db8:10::100', $again->first()->address);

        $this->expectException(NoAddressesException::class);
        $this->allocator->allocate($this->serverOn($node), $node, 0, 1);
    }

    public function test_maszyna_dostaje_ipv4_jako_glowny_i_ipv6_obok(): void
    {
        $node = Hypervisor::factory()->create();
        $this->createPool([
            'hypervisor_id' => $node->id, 'cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1',
        ]);
        $this->createPool([
            'hypervisor_id' => $node->id,
            'cidr' => '2001:db8:10::/64', 'gateway' => '2001:db8:10::1', 'prefix' => 64,
        ]);

        $addresses = $this->allocator->allocate($this->serverOn($node), $node, 1, 1);

        $this->assertSame([4, 6], $addresses->pluck('version')->all());
        $this->assertTrue($addresses->first()->is_primary);
        $this->assertFalse($addresses->last()->is_primary);
    }

    // --- API administratora --------------------------------------------------

    public function test_api_grupy_i_puli_ipv6(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $node = Hypervisor::factory()->create();

        $groupId = $this->actingAs($admin)
            ->postJson('/api/v1/admin/hypervisor-groups', ['name' => 'DC-API', 'hypervisor_ids' => [$node->id]])
            ->assertCreated()
            ->assertJsonPath('data.hypervisors.0.id', $node->id)
            ->json('data.id');

        $poolId = $this->actingAs($admin)
            ->postJson('/api/v1/admin/ip-pools', [
                'scope' => 'group',
                'hypervisor_group_id' => $groupId,
                'name' => 'v6',
                'cidr' => '2001:db8:20::/64',
                'gateway' => '2001:db8:20::1',
                'prefix' => 64,
            ])
            ->assertCreated()
            ->assertJsonPath('imported', 0)
            ->json('pool_id');

        $this->actingAs($admin)->getJson('/api/v1/admin/ip-pools')
            ->assertOk()
            ->assertJsonPath('data.0.scope', 'group')
            ->assertJsonPath('data.0.hypervisor_group', 'DC-API')
            ->assertJsonPath('data.0.free', null);

        // Grupa z pulą zostaje; po usunięciu puli da się ją skasować.
        $this->actingAs($admin)->deleteJson("/api/v1/admin/hypervisor-groups/{$groupId}")->assertStatus(422);
        $this->actingAs($admin)->deleteJson("/api/v1/admin/ip-pools/{$poolId}")->assertOk();
        $this->actingAs($admin)->deleteJson("/api/v1/admin/hypervisor-groups/{$groupId}")->assertOk();
        $this->assertNull($node->fresh()->hypervisor_group_id);
    }

    public function test_api_odrzuca_brame_innej_rodziny(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/ip-pools', [
                'hypervisor_id' => Hypervisor::factory()->create()->id,
                'name' => 'zla',
                'cidr' => '2001:db8:20::/64',
                'gateway' => '203.0.113.1',
                'prefix' => 64,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gateway');
    }

    // --- zamówienie od początku do końca --------------------------------------

    public function test_zamowienie_pakietu_nat_trafia_na_wezel_z_pula_nat(): void
    {
        Queue::fake();

        // Pełniejszy węzeł wygrałby bin-packing, ale nie ma adresów NAT.
        Hypervisor::factory()->create(['cpu_cores_used' => 40, 'ram_mb_used' => 131072]);
        $withNat = Hypervisor::factory()->create();
        $this->createPool([
            'hypervisor_id' => $withNat->id, 'type' => 'nat',
            'cidr' => '10.10.0.0/24', 'gateway' => '10.10.0.1',
            'nat_port_start' => 10000, 'nat_ports_per_server' => 20,
        ]);
        $this->createPool([
            'hypervisor_id' => $withNat->id, 'type' => 'nat',
            'cidr' => 'fd00:10::/64', 'gateway' => 'fd00:10::1', 'prefix' => 64,
        ]);

        VpsPackage::factory()->create([
            'slug' => 'nat-mini', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 10,
            'ip_count' => 1, 'ipv6_count' => 1, 'network_type' => 'nat',
        ]);
        $template = OsTemplate::factory()->create();
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer)->postJson('/api/v1/servers', [
            'package' => 'nat-mini',
            'template' => $template->id,
            'hostname' => 'nat1.example.com',
        ])->assertCreated();

        $server = Server::firstOrFail();
        $this->assertSame($withNat->id, $server->hypervisor_id);

        $interfaces = ServerPayload::interfaces($server);
        $this->assertSame(['nat', 'nat'], array_column($interfaces, 'mode'));
        $this->assertSame([4, 6], array_column($interfaces, 'version'));
        $this->assertNull($interfaces[1]['nat']['port_from'], 'IPv6 za NAT-em nie ma przekierowań');

        $response->assertJsonPath('data.ip_addresses.0.type', 'nat')
            ->assertJsonPath('data.ip_addresses.0.nat_ports.ssh', $interfaces[0]['nat']['port_from']);
    }

    public function test_brak_adresow_wskazuje_pule_a_nie_pojemnosc(): void
    {
        Queue::fake();
        Hypervisor::factory()->create();
        VpsPackage::factory()->create(['slug' => 'nat-mini', 'network_type' => 'nat', 'ip_count' => 1]);
        $template = OsTemplate::factory()->create();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->postJson('/api/v1/servers', [
                'package' => 'nat-mini',
                'template' => $template->id,
                'hostname' => 'nat2.example.com',
            ])
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'adres'));
    }
}
