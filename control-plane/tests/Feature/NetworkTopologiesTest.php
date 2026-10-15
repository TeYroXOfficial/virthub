<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Domain\Network\Firewall;
use App\Domain\Network\PrivateNetworks;
use App\Domain\Provisioning\Migrations;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NetworkTopologiesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Hypervisor $a;

    private Hypervisor $b;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->create();
        $group = HypervisorGroup::factory()->create();
        $node = fn (string $name, ?string $vxlan) => Hypervisor::factory()->create(['name' => $name, 'virtualization' => 'kvm', 'hypervisor_group_id' => $group->id,
            'vxlan_address' => $vxlan, 'cpu_cores_total' => 64, 'ram_mb_total' => 262144, 'disk_gb_total' => 4000, 'public_address' => "$name.example.net"]);
        $this->a = $node('kvm-a', '10.10.0.1');
        $this->b = $node('kvm-b', '10.10.0.2');
    }

    private function server(Hypervisor $node): Server
    {
        return Server::factory()->create(['user_id' => $this->owner->id, 'hypervisor_id' => $node->id, 'agent_uuid' => fake()->uuid()]);
    }

    public function test_siec_w_grupie_laczy_wezly_tunelem(): void
    {
        $service = app(PrivateNetworks::class);
        $network = $service->create($this->owner, $this->a, 'backend');
        $this->assertTrue($network->spansGroup());

        $x = $this->server($this->a);
        $y = $this->server($this->b);
        $service->attach($x, $network);
        $service->attach($y, $network);

        $nic = ServerPayload::forCreate($y->fresh())['private_nics'][0];
        $this->assertSame(['vni' => $network->vni(), 'local' => '10.10.0.2', 'peers' => ['10.10.0.1'], 'mtu' => 1450], $nic['vxlan']);
        // Podłączenie maszyny na kvm-b odświeża tunel na kvm-a (inny węzeł sieci).
        $this->assertTrue(ServerJob::where('action', 'private_nics')->where('server_id', $x->id)->count() >= 2);

        $outside = Hypervisor::factory()->create(['virtualization' => 'kvm', 'vxlan_address' => '10.20.0.9']);
        $this->expectException(\DomainException::class);
        $service->attach($this->server($outside), $network);
    }

    public function test_wezel_bez_vxlan_daje_siec_lokalna_i_migracja(): void
    {
        $this->b->forceFill(['vxlan_address' => null])->save();
        $service = app(PrivateNetworks::class);
        $local = $service->create($this->owner, $this->b, 'lokalna');
        $this->assertFalse($local->spansGroup());

        $pool = IpPool::factory()->forGroup($this->a->group)->create();
        $mover = $this->server($this->a);
        IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $this->a->id, 'server_id' => $mover->id, 'is_primary' => true]);
        $group = $service->create($this->owner, $this->a, 'grupowa');
        $service->attach($mover, $group);

        $third = Hypervisor::factory()->create(['virtualization' => 'kvm', 'hypervisor_group_id' => $this->a->hypervisor_group_id, 'vxlan_address' => '10.10.0.3',
            'cpu_cores_total' => 64, 'ram_mb_total' => 262144, 'disk_gb_total' => 4000]);
        app(Migrations::class)->assertMigratable($mover->fresh(), $third, 'cold');
        $payload = app(Migrations::class)->payloadFor($mover->fresh(), $third);
        $this->assertSame('10.10.0.3', $payload['private_nics'][0]['vxlan']['local'], 'Tunel zestawiany z węzła docelowego');

        $this->expectException(\DomainException::class);
        app(Migrations::class)->assertMigratable($mover->fresh(), $this->b->fresh(), 'cold');
    }

    public function test_regula_zapory_na_karcie_prywatnej(): void
    {
        $server = $this->server($this->a);
        $network = app(PrivateNetworks::class)->create($this->owner, $this->a, 'db');
        app(PrivateNetworks::class)->attach($server, $network);

        $firewall = app(Firewall::class);
        $rule = $firewall->addRule($server, $this->owner, ['action' => 'drop', 'direction' => 'in', 'protocol' => 'tcp', 'port_from' => 5432, 'interface' => 'n1']);
        $this->assertSame('n1', $rule->interface);
        $payload = ServerPayload::forNetwork($server->fresh());
        $this->assertSame('n1', $payload['firewall'][0]['interface']);
        $this->assertSame(1, $payload['private_nics'][0]['slot']);

        $this->expectException(ValidationException::class);
        $firewall->addRule($server, $this->owner, ['action' => 'drop', 'direction' => 'in', 'protocol' => 'tcp', 'port_from' => 1, 'interface' => 'n3']);
    }

    public function test_widoki(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->a->forceFill(['last_health' => ['network_backend' => 'ovs']])->save();
        $this->actingAs($admin)->get(route('panel.admin.hypervisors.show', $this->a))->assertOk()
            ->assertSee('Adres w sieci wewnętrznej (VXLAN)')->assertSee('Open vSwitch')->assertSee('zapora klienta na karcie głównej nie działa');

        $server = $this->server($this->a);
        app(PrivateNetworks::class)->attach($server, app(PrivateNetworks::class)->create($this->owner, $this->a, 'app'));
        $this->actingAs($this->owner)->get(route('panel.servers.show', $server))->assertOk()->assertSee('cała grupa')->assertSee('sieć prywatna app');
        $this->assertSame(1, PrivateNetwork::count());
    }
}
