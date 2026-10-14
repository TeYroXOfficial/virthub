<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvancedNetworkTest extends TestCase
{
    use RefreshDatabase;

    private function pool(array $extra): IpPool
    {
        $node = Hypervisor::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->post(route('panel.admin.ip-pools.store'), [
            'hypervisor_id' => $node->id, 'name' => 'Pula', 'cidr' => '198.51.100.0/28', 'gateway' => '198.51.100.1', 'prefix' => 28,
            'fill' => 'subnet', 'nameservers' => '1.1.1.1',
        ] + $extra)->assertSessionHasNoErrors();

        return IpPool::latest('id')->firstOrFail();
    }

    public function test_pula_vlan_trafia_do_agenta(): void
    {
        $pool = $this->pool(['vlan_id' => 120]);
        $this->assertSame(120, $pool->vlan_id);
        $server = Server::factory()->create(['hypervisor_id' => $pool->hypervisor_id]);
        $ip = IpAddress::where('ip_pool_id', $pool->id)->firstOrFail();
        $ip->forceFill(['server_id' => $server->id])->save();

        [$interface] = ServerPayload::interfaces($server);
        $this->assertSame(120, $interface['vlan']);
        $this->assertSame('bridged', $interface['mode']);
        $this->assertSame(28, $interface['prefix']);
    }

    public function test_pula_routowana_daje_adres_32_i_brame_on_link(): void
    {
        $pool = $this->pool(['routed' => '1']);
        $this->assertTrue($pool->isRouted());
        $server = Server::factory()->create(['hypervisor_id' => $pool->hypervisor_id]);
        IpAddress::where('ip_pool_id', $pool->id)->firstOrFail()->forceFill(['server_id' => $server->id])->save();

        [$interface] = ServerPayload::interfaces($server);
        $this->assertSame(['routed', 32, '169.254.0.1'], [$interface['mode'], $interface['prefix'], $interface['gateway']]);
        $this->assertArrayNotHasKey('vlan', $interface);
    }

    public function test_zwykla_pula_bez_zmian(): void
    {
        $pool = $this->pool([]);
        $this->assertNull($pool->vlan_id);
        $this->assertFalse($pool->isRouted());
        $this->get(route('panel.admin.ip-pools'))->assertOk()->assertSee(__('Podsieć routowana do węzła'));
    }
}
