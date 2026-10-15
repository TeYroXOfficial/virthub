<?php

namespace Tests\Feature;

use App\Domain\Provisioning\HypervisorSelector;
use App\Domain\Provisioning\IpAllocator;
use App\Models\BillingService;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use App\Models\IpAddress;
use App\Models\IpAssignment;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Operacje administracyjne: kolejność pul, rozmieszczanie, audyt IP, status i metryki, udostępnianie, właściciel. */
class OperationsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function node(string $name, int $usedCpu, ?HypervisorGroup $group = null): Hypervisor
    {
        return Hypervisor::factory()->create(['name' => $name, 'virtualization' => 'kvm', 'hypervisor_group_id' => $group?->id,
            'cpu_cores_total' => 16, 'cpu_cores_used' => $usedCpu, 'ram_mb_total' => 65536, 'ram_mb_used' => $usedCpu * 4096, 'disk_gb_total' => 1000, 'disk_gb_used' => $usedCpu * 50]);
    }

    public function test_kolejnosc_pul_ip(): void
    {
        $node = $this->node('n1', 0);
        $first = IpPool::factory()->create(['hypervisor_id' => $node->id, 'priority' => 100]);
        $second = IpPool::factory()->create(['hypervisor_id' => $node->id, 'priority' => 10]);
        IpAddress::factory()->create(['ip_pool_id' => $first->id, 'hypervisor_id' => $node->id]);
        IpAddress::factory()->create(['ip_pool_id' => $second->id, 'hypervisor_id' => $node->id]);
        $server = Server::factory()->create(['hypervisor_id' => $node->id]);

        $taken = app(IpAllocator::class)->allocate($server, $node);
        $this->assertSame($second->id, $taken->first()->ip_pool_id, 'Pula z mniejszym priorytetem idzie pierwsza');

        $this->actingAs($this->admin)->put(route('panel.admin.ip-pools.update', $first), [
            'scope' => 'hypervisor', 'hypervisor_id' => $node->id, 'name' => $first->name, 'gateway' => $first->gateway, 'prefix' => $first->prefix, 'priority' => 5,
        ])->assertSessionHasNoErrors();
        $this->assertSame(5, $first->fresh()->priority);
    }

    public function test_strategie_rozmieszczania(): void
    {
        $group = HypervisorGroup::factory()->create();
        $busy = $this->node('busy', 10, $group);
        $empty = $this->node('empty', 1, $group);
        Server::factory()->count(3)->create(['hypervisor_id' => $empty->id]);
        $selector = app(HypervisorSelector::class);

        $this->assertSame('busy', $selector->select(2, 2048, 20)->name, 'domyślnie upakowanie');
        Setting::put(['placement.strategy' => 'spread']);
        $this->assertSame('empty', $selector->select(2, 2048, 20)->name);
        $group->forceFill(['placement_strategy' => 'fewest'])->save();
        $this->assertSame('busy', $selector->select(2, 2048, 20)->name, 'strategia grupy: najmniej maszyn');
        $this->assertContains($selector->select(2, 2048, 20, accepts: null)?->name, ['busy', 'empty']);

        $this->actingAs($this->admin)->put(route('panel.admin.hypervisor-groups.placement'), ['placement_strategy' => 'random'])->assertSessionHasNoErrors();
        $this->assertSame('random', HypervisorSelector::defaultStrategy());
        $this->get(route('panel.admin.hypervisor-groups'))->assertOk()->assertSee('najmniej maszyn');
    }

    public function test_audyt_ip_historia_i_eksport(): void
    {
        $node = $this->node('n1', 0);
        $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
        $address = IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id, 'address' => '198.51.100.20']);
        $alice = User::factory()->create(['email' => 'alice@example.com']);
        $server = Server::factory()->create(['user_id' => $alice->id, 'hypervisor_id' => $node->id, 'hostname' => 'abuse.example.com']);

        app(IpAllocator::class)->allocate($server, $node);
        $this->travel(2)->days();
        app(IpAllocator::class)->releaseAll($server);
        $server->forceDelete();

        $row = IpAssignment::firstOrFail();
        $this->assertSame('198.51.100.20', $row->address);
        $this->assertSame('alice@example.com', $row->user_email);
        $this->assertSame('abuse.example.com', $row->hostname);
        $this->assertNotNull($row->released_at);

        $this->actingAs($this->admin)->get(route('panel.admin.network.ip-audit', ['q' => '198.51.100.20']))->assertOk()->assertSee('abuse.example.com')->assertSee('alice@example.com');
        $csv = $this->get(route('panel.admin.network.ip-audit.export', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('198.51.100.20,4,abuse.example.com', $csv);
        $json = json_decode($this->get(route('panel.admin.network.ip-audit.export', ['format' => 'json', 'state' => 'released']))->streamedContent(), true);
        $this->assertSame('alice@example.com', $json[0]['user_email']);
        $yaml = $this->get(route('panel.admin.network.ip-audit.export', ['format' => 'yaml']))->streamedContent();
        $this->assertStringContainsString('- address: "198.51.100.20"', $yaml);
        $this->assertStringContainsString('  user_email: "alice@example.com"', $yaml);
        $this->get(route('panel.admin.network.ip-audit.export', ['format' => 'json', 'state' => 'active']))->assertOk();
        $this->assertSame("[]\n", $this->get(route('panel.admin.network.ip-audit.export', ['format' => 'json', 'state' => 'active']))->streamedContent());
    }

    public function test_status_panelu_i_metryki_wezla(): void
    {
        $node = $this->node('n1', 4);
        $node->forceFill(['enrolled_at' => now(), 'last_seen_at' => now(), 'status' => Hypervisor::STATUS_ONLINE,
            'last_health' => ['cpu_load_1m' => 4.0, 'cpu_cores_total' => 16, 'ram_mb_total' => 65536, 'ram_mb_free' => 32768, 'disk_gb_total' => 1000, 'disk_gb_free' => 750]])->save();
        Server::factory()->create(['hypervisor_id' => $node->id]);
        Sanctum::actingAs($this->admin, ['admin']);

        $this->getJson('/api/v1/admin/status')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.database', true)
            ->assertJsonPath('nodes.online', 1)->assertJsonPath('servers.total', 1);
        $this->getJson("/api/v1/admin/hypervisors/{$node->id}/metrics")->assertOk()
            ->assertJsonPath('data.host.cpu_percent', 25)->assertJsonPath('data.host.memory_percent', 50)->assertJsonPath('data.host.disk_percent', 25)
            ->assertJsonPath('data.allocation.cpu_cores.used', 4)->assertJsonPath('data.servers', 1);

        $node->forceFill(['last_seen_at' => now()->subHour(), 'status' => Hypervisor::STATUS_OFFLINE])->save();
        $this->getJson('/api/v1/admin/status')->assertStatus(503)->assertJsonPath('status', 'degraded')->assertJsonPath('nodes.offline.0', 'n1');
        Sanctum::actingAs(User::factory()->create(), ['servers:read']);
        $this->getJson('/api/v1/admin/status')->assertForbidden();
    }

    public function test_udostepnianie_maszyny(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create(['email' => 'kolega@example.com']);
        $server = Server::factory()->create(['user_id' => $owner->id, 'hostname' => 'wspolna.example.com']);

        $this->actingAs($friend)->get(route('panel.servers.show', $server))->assertForbidden();
        $this->actingAs($owner)->post(route('panel.servers.shares.store', $server), ['email' => 'kolega@example.com', 'permissions' => ['servers.delete']])
            ->assertSessionHasErrors('permissions.0');
        $this->post(route('panel.servers.shares.store', $server), ['email' => 'kolega@example.com', 'permissions' => ['servers.power']])->assertSessionHasNoErrors();
        $share = $server->shares()->firstOrFail();
        $this->assertSame(['servers.power'], $share->permissions);

        $this->actingAs($friend)->get(route('panel.dashboard'))->assertOk()->assertSee('wspolna.example.com')->assertSee('udostępniona');
        $this->get(route('panel.servers.show', $server))->assertOk()->assertSee('Zrezygnuj z dostępu');
        $this->assertTrue($friend->can('power', $server));
        $this->assertFalse($friend->can('console', $server));
        $this->assertFalse($friend->can('destroy', $server));
        $this->assertFalse($friend->can('share', $server));
        $this->post(route('panel.servers.shares.store', $server), ['email' => 'x@example.com'])->assertForbidden();
        Sanctum::actingAs($friend, ['servers:read']);
        $this->getJson('/api/v1/servers')->assertOk()->assertJsonFragment(['hostname' => 'wspolna.example.com']);

        $this->actingAs($owner)->post(route('panel.servers.shares.store', $server), ['email' => 'nie-ma@example.com'])->assertSessionHasErrors('sharing');
        $this->actingAs($friend)->delete(route('panel.servers.shares.destroy', [$server, $share]))->assertRedirect(route('panel.dashboard'));
        $this->assertFalse($friend->fresh()->can('view', $server->fresh()));
    }

    public function test_zmiana_wlasciciela(): void
    {
        $node = $this->node('n1', 0);
        $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
        IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id]);
        $old = User::factory()->create(['email' => 'stary@example.com']);
        $new = User::factory()->create(['email' => 'nowy@example.com']);
        $server = Server::factory()->create(['user_id' => $old->id, 'hypervisor_id' => $node->id]);
        app(IpAllocator::class)->allocate($server, $node);
        $service = BillingService::create(['user_id' => $old->id, 'server_id' => $server->id, 'name' => 'VPS', 'cycle' => 'monthly', 'amount' => 1000, 'status' => 'active']);

        $this->actingAs($this->admin)->post(route('panel.servers.owner', $server), ['email' => 'nowy@example.com'])->assertSessionHasErrors('sharing');
        $this->post(route('panel.servers.owner', $server), ['email' => 'nowy@example.com', 'with_billing' => 1])->assertSessionHasNoErrors();
        $this->assertSame($new->id, $server->fresh()->user_id);
        $this->assertSame($new->id, $service->fresh()->user_id);
        $this->assertSame(['stary@example.com', 'nowy@example.com'], IpAssignment::orderBy('id')->pluck('user_email')->all());
        $this->assertNotNull(IpAssignment::orderBy('id')->first()->released_at);

        $this->actingAs($old)->get(route('panel.servers.show', $server))->assertForbidden();
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $this->assertFalse($support->can('transfer', $server));

        Sanctum::actingAs($this->admin, ['admin']);
        $this->postJson("/api/v1/admin/servers/{$server->id}/owner", ['email' => 'stary@example.com', 'with_billing' => true])->assertOk()->assertJsonPath('data.email', 'stary@example.com');
    }
}
