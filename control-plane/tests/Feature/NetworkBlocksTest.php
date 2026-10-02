<?php

namespace Tests\Feature;

use App\Domain\Provisioning\IpAllocator;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sieć: bloki IP (tworzenie, edycja, dodawanie adresów, resolwery) i przegląd adresów. */
class NetworkBlocksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Hypervisor $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->node = Hypervisor::factory()->create(['name' => 'waw-1', 'enrolled_at' => now()]);
    }

    private function create(array $overrides = []): IpPool
    {
        $this->actingAs($this->admin)->post(route('panel.admin.ip-pools.store'), $overrides + [
            'name' => 'Blok', 'type' => 'public', 'cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1', 'prefix' => 24,
            'scope' => 'hypervisor', 'hypervisor_id' => $this->node->id, 'dns_preset' => 'google', 'fill' => 'none',
        ])->assertSessionHasNoErrors();

        return IpPool::query()->latest('id')->firstOrFail();
    }

    private function add(IpPool $pool, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post(route('panel.admin.ip-pools.addresses', $pool), $data);
    }

    public function test_pusty_blok_i_dodawanie_pojedynczych_zakresu_i_podsieci(): void
    {
        $pool = $this->create();
        $this->assertSame(0, $pool->addresses()->count());
        $this->assertSame(['8.8.8.8', '8.8.4.4'], $pool->nameservers);

        $this->add($pool, ['mode' => 'single', 'addresses' => "203.0.113.5\n203.0.113.6, 203.0.113.1"])->assertSessionHasNoErrors();
        $this->assertSame(3, $pool->addresses()->count());
        $this->assertTrue($pool->addresses()->where('address', '203.0.113.1')->value('is_reserved'), 'brama zarezerwowana');

        $this->add($pool, ['mode' => 'range', 'from' => '203.0.113.6', 'to' => '203.0.113.10'])
            ->assertSessionHas('status', 'Dodano 4 adresów (1 już było w panelu).');
        $this->add($pool, ['mode' => 'subnet', 'cidr' => '203.0.113.16/28'])->assertSessionHasNoErrors();
        $this->assertSame(3 + 4 + 16, $pool->addresses()->count());

        // Cała podsieć bloku: bez adresu sieci i rozgłoszeniowego.
        $this->add($pool, ['mode' => 'subnet', 'cidr' => '203.0.113.0/24'])->assertSessionHasNoErrors();
        $this->assertSame(254, $pool->addresses()->count());
        $this->assertFalse($pool->addresses()->where('address', '203.0.113.255')->exists());

        $this->add($pool, ['mode' => 'single', 'addresses' => '198.51.100.1'])->assertSessionHasErrors('addresses');
        $this->add($pool, ['mode' => 'range', 'from' => '203.0.113.20', 'to' => '203.0.113.10'])->assertSessionHasErrors('to');
        $this->add($pool, ['mode' => 'subnet', 'cidr' => '203.0.112.0/23'])->assertSessionHasErrors('cidr');
    }

    public function test_wypelnienie_zakresem_przy_tworzeniu(): void
    {
        $pool = $this->create(['fill' => 'range', 'range_from' => '203.0.113.10', 'range_to' => '203.0.113.19']);
        $this->assertSame(10, $pool->addresses()->count());

        $this->actingAs($this->admin)->post(route('panel.admin.ip-pools.store'), [
            'name' => 'X', 'type' => 'public', 'cidr' => '198.51.100.0/24', 'gateway' => '198.51.100.1', 'prefix' => 24,
            'scope' => 'hypervisor', 'hypervisor_id' => $this->node->id, 'fill' => 'range',
        ])->assertSessionHasErrors('range_from');
    }

    public function test_ipv6_pojedyncze_adresy_i_resolwery_ipv6(): void
    {
        $pool = $this->create(['cidr' => '2001:db8:10::/64', 'gateway' => '2001:db8:10::1', 'prefix' => 64, 'dns_preset' => 'cloudflare']);
        $this->assertSame(['2606:4700:4700::1111', '2606:4700:4700::1001'], $pool->nameservers);

        $this->add($pool, ['mode' => 'range', 'from' => '2001:db8:10::100', 'to' => '2001:db8:10::10f'])->assertSessionHasNoErrors();
        $this->assertSame(16, $pool->addresses()->count());
        $this->add($pool, ['mode' => 'subnet', 'cidr' => '2001:db8:10::/120'])->assertSessionHasErrors('cidr');

        // Dodany ręcznie wolny adres IPv6 jest użyty przed generowaniem nowych.
        $server = Server::factory()->create(['hypervisor_id' => $this->node->id]);
        $picked = app(IpAllocator::class)->allocate($server, $this->node, 0, 1);
        $this->assertSame('2001:db8:10::100', $picked->first()->address);

        $this->actingAs($this->admin)->get(route('panel.admin.network.ipv6'))
            ->assertOk()->assertSee('2001:db8:10::100')->assertSee($server->hostname);
    }

    public function test_edycja_bloku_przenosi_wolne_adresy_i_zmienia_resolwery(): void
    {
        $pool = $this->create(['fill' => 'subnet']);
        $other = Hypervisor::factory()->create(['name' => 'waw-2']);
        $server = Server::factory()->create(['hypervisor_id' => $this->node->id]);
        app(IpAllocator::class)->allocate($server, $this->node);

        $this->actingAs($this->admin)->put(route('panel.admin.ip-pools.update', $pool), [
            'name' => 'Nowa nazwa', 'gateway' => '203.0.113.1', 'prefix' => 24,
            'scope' => 'hypervisor', 'hypervisor_id' => $other->id,
            'dns_preset' => 'custom', 'nameservers' => '9.9.9.9, 1.1.1.1',
        ])->assertSessionHasNoErrors();

        $pool->refresh();
        $this->assertSame('Nowa nazwa', $pool->name);
        $this->assertSame($other->id, $pool->hypervisor_id);
        $this->assertSame(['9.9.9.9', '1.1.1.1'], $pool->nameservers);
        $this->assertSame(0, $pool->addresses()->whereNull('server_id')->where('hypervisor_id', $this->node->id)->count());
        $this->assertSame($this->node->id, $server->ipAddresses()->first()->hypervisor_id, 'przydzielony zostaje przy maszynie');

        $this->actingAs($this->admin)->put(route('panel.admin.ip-pools.update', $pool), [
            'name' => 'X', 'gateway' => '198.51.100.1', 'prefix' => 24, 'scope' => 'hypervisor', 'hypervisor_id' => $other->id,
        ])->assertSessionHasNoErrors(); // brama poza siecią jest dozwolona dla bloku publicznego (on-link u dostawcy)

        $this->actingAs($this->admin)->get(route('panel.admin.ip-pools.show', $pool))->assertOk()->assertSee('Ustawienia bloku');
    }

    public function test_rezerwacja_i_usuwanie_wolnego_adresu(): void
    {
        $pool = $this->create();
        $this->add($pool, ['mode' => 'single', 'addresses' => '203.0.113.50, 203.0.113.51']);
        [$a, $b] = $pool->addresses()->orderBy('address')->get()->all();

        $this->actingAs($this->admin)->post(route('panel.admin.ip-addresses.update', $a), ['action' => 'reserve']);
        $this->assertTrue($a->fresh()->is_reserved);
        $this->actingAs($this->admin)->post(route('panel.admin.ip-addresses.update', $a), ['action' => 'unreserve']);
        $this->assertFalse($a->fresh()->is_reserved);

        $b->forceFill(['server_id' => Server::factory()->create(['hypervisor_id' => $this->node->id])->id])->save();
        $this->actingAs($this->admin)->post(route('panel.admin.ip-addresses.update', $b), ['action' => 'delete'])->assertSessionHasErrors('address');
        $this->actingAs($this->admin)->post(route('panel.admin.ip-addresses.update', $a), ['action' => 'delete']);
        $this->assertNull($a->fresh());
    }

    public function test_przeglad_adresow_ipv4_i_nat(): void
    {
        $pool = $this->create(['fill' => 'range', 'range_from' => '203.0.113.10', 'range_to' => '203.0.113.12']);
        $server = Server::factory()->create(['hypervisor_id' => $this->node->id, 'hostname' => 'vps.example.com']);
        app(IpAllocator::class)->allocate($server, $this->node);

        $this->actingAs($this->admin)->get(route('panel.admin.network.ipv4'))
            ->assertOk()->assertSee('203.0.113.10')->assertSee('vps.example.com')->assertSee('Blok');
        $this->actingAs($this->admin)->get(route('panel.admin.network.ipv4', ['status' => 'free']))
            ->assertOk()->assertDontSee('vps.example.com')->assertSee('203.0.113.11');
        $this->actingAs($this->admin)->get(route('panel.admin.network.ipv4', ['q' => 'vps.example']))
            ->assertOk()->assertSee('203.0.113.10')->assertDontSee('203.0.113.11');

        $this->create([
            'name' => 'NAT', 'type' => 'nat', 'cidr' => '10.10.0.0/24', 'gateway' => '10.10.0.1', 'fill' => 'range',
            'range_from' => '10.10.0.5', 'range_to' => '10.10.0.6', 'nat_port_start' => 10000, 'nat_ports_per_server' => 10,
        ]);
        $this->actingAs($this->admin)->get(route('panel.admin.network.nat'))
            ->assertOk()->assertSee('10.10.0.5')->assertSee('10050–10059')->assertDontSee('203.0.113.10');
        $this->actingAs($this->admin)->get(route('panel.admin.network.ipv4'))->assertDontSee('10.10.0.5');

        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $this->actingAs($support)->get(route('panel.admin.network.ipv4'))->assertForbidden();
    }

    public function test_lista_blokow_z_filtrem_wersji(): void
    {
        $this->create(['name' => 'Blok v4']);
        $this->create(['name' => 'Blok v6', 'cidr' => '2001:db8::/64', 'gateway' => '2001:db8::1', 'prefix' => 64]);

        $this->actingAs($this->admin)->get(route('panel.admin.ip-pools', ['version' => 6]))
            ->assertOk()->assertSee('Blok v6')->assertDontSee('Blok v4')->assertSee('2001:4860:4860::8888');
    }
}
