<?php

namespace Tests\Feature;

use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\OsTemplateGroup;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Sprzątanie maszyn, ustawienia węzłów i grup, systemy z wieloma wersjami. */
class FleetManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /** Maszyna z adresem IP i zasobami zarezerwowanymi na węźle. */
    private function serverWithIp(?Hypervisor $node = null, array $attributes = []): Server
    {
        $node ??= Hypervisor::factory()->create(['cpu_cores_used' => 2, 'ram_mb_used' => 4096, 'disk_gb_used' => 50]);
        $server = Server::factory()->create(['hypervisor_id' => $node->id, ...$attributes]);
        $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
        IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id, 'server_id' => $server->id]);

        return $server;
    }

    // --- usuwanie maszyn --------------------------------------------------------

    public function test_admin_usuwa_maszyne_z_listy(): void
    {
        $server = $this->serverWithIp();

        $this->actingAs($this->admin)
            ->post(route('panel.admin.servers.bulk'), ['row' => "delete:{$server->id}"])
            ->assertSessionHasNoErrors();

        $this->assertSame(ServerState::Deleting, $server->fresh()->state);
        $this->assertDatabaseHas('server_jobs', ['server_id' => $server->id, 'action' => 'delete']);
    }

    public function test_usuniecie_tylko_z_panelu_zwalnia_adresy_i_zasoby(): void
    {
        $server = $this->serverWithIp();
        $server->markState(ServerState::Deleting);
        $stuck = ServerJob::create(['server_id' => $server->id, 'action' => 'delete', 'status' => ServerJob::STATUS_RUNNING]);

        $this->actingAs($this->admin)
            ->post(route('panel.servers.purge', $server), ['confirm' => '1'])
            ->assertRedirect(route('panel.admin.servers'));

        $this->assertSoftDeleted($server);
        $this->assertNull(IpAddress::first()->server_id, 'adres wraca do puli');
        $this->assertSame(0, $server->hypervisor->fresh()->cpu_cores_used);
        $this->assertSame(ServerJob::STATUS_FAILED, $stuck->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'server.purged']);
    }

    public function test_zbiorcze_usuniecie_z_panelu(): void
    {
        $a = $this->serverWithIp();
        $b = Server::factory()->create(['state' => ServerState::Error, 'agent_uuid' => null]);

        $this->actingAs($this->admin)->post(route('panel.admin.servers.bulk'), [
            'ids' => [$a->id, $b->id], 'action' => 'purge',
        ])->assertSessionHasNoErrors();

        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
    }

    public function test_support_nie_usuwa_z_panelu_ani_cudzych_maszyn(): void
    {
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $server = $this->serverWithIp();

        $this->actingAs($support)->post(route('panel.servers.purge', $server), ['confirm' => '1'])->assertForbidden();
        $this->actingAs($support)->post(route('panel.admin.servers.bulk'), ['ids' => [$server->id], 'action' => 'delete'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Pominięto'));

        $this->assertNotSoftDeleted($server);
    }

    public function test_klient_usuwa_wlasna_maszyne_w_ustawieniach(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $server = $this->serverWithIp(null, ['user_id' => $customer->id]);

        $this->actingAs($customer)->get(route('panel.servers.show', $server))
            ->assertSee('Usuń maszynę')->assertDontSee('Usuń tylko z panelu');

        $this->actingAs($customer)->delete(route('panel.servers.destroy', $server))->assertSessionHasErrors('confirm');
        $this->actingAs($customer)->delete(route('panel.servers.destroy', $server), ['confirm' => '1'])
            ->assertRedirect(route('panel.dashboard'));
        $this->assertSame(ServerState::Deleting, $server->fresh()->state);

        $other = $this->serverWithIp(null, ['user_id' => User::factory()->create()->id]);
        $this->actingAs($customer)->delete(route('panel.servers.destroy', $other), ['confirm' => '1'])->assertForbidden();
    }

    // --- węzły ------------------------------------------------------------------

    public function test_strona_wezla_i_nowe_ustawienia(): void
    {
        $node = Hypervisor::factory()->create(['enrolled_at' => now(), 'name' => 'node-a']);
        $server = $this->serverWithIp($node, ['hostname' => 'na-wezle.example.com']);

        $this->actingAs($this->admin)->get(route('panel.admin.hypervisors.show', $node))
            ->assertOk()->assertSee('na-wezle.example.com')->assertSee('Limit maszyn');

        $this->actingAs($this->admin)->put(route('panel.admin.hypervisors.update', $node), [
            'name' => 'node-waw-1', 'max_servers' => 1, 'notes' => 'szafa 12',
            'cpu_cores_total' => 64, 'ram_mb_total' => 262144, 'disk_gb_total' => 4000,
            'bridge' => 'br0', 'status' => 'online', 'accepts_new_servers' => 1,
        ])->assertSessionHasNoErrors();

        $node->refresh();
        $this->assertSame('node-waw-1', $node->name);
        $this->assertSame(1, $node->max_servers);
        $this->assertSame('szafa 12', $node->notes);
        $this->assertFalse($node->acceptsMoreServers(), 'limit 1 maszyny osiągnięty');
    }

    public function test_usuniecie_martwego_wezla_razem_z_maszynami(): void
    {
        $node = Hypervisor::factory()->create(['name' => 'martwy']);
        $server = $this->serverWithIp($node);

        $this->actingAs($this->admin)->delete(route('panel.admin.hypervisors.destroy', $node))
            ->assertSessionHasErrors('delete');

        $this->actingAs($this->admin)->delete(route('panel.admin.hypervisors.destroy', $node), [
            'purge_servers' => 1, 'confirm_name' => 'zly',
        ])->assertSessionHasErrors('delete');

        $this->actingAs($this->admin)->delete(route('panel.admin.hypervisors.destroy', $node), [
            'purge_servers' => 1, 'confirm_name' => 'martwy',
        ])->assertRedirect(route('panel.admin.hypervisors'));

        $this->assertModelMissing($node);
        $this->assertSoftDeleted($server);
    }

    // --- grupy węzłów -----------------------------------------------------------

    public function test_lokalizacja_do_wyboru_przy_zamowieniu(): void
    {
        $waw = HypervisorGroup::create(['name' => 'DC1', 'location' => 'Warszawa', 'is_public' => true]);
        $fra = HypervisorGroup::create(['name' => 'DC2', 'location' => 'Frankfurt', 'is_public' => true]);
        $hidden = HypervisorGroup::create(['name' => 'Test', 'is_public' => false]);
        $nodeWaw = Hypervisor::factory()->create(['hypervisor_group_id' => $waw->id]);
        $nodeFra = Hypervisor::factory()->create(['hypervisor_group_id' => $fra->id]);
        Hypervisor::factory()->create(['hypervisor_group_id' => $hidden->id]);
        foreach ([$nodeWaw, $nodeFra] as $node) {
            $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
            IpAddress::factory()->count(2)->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id]);
        }
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $template = OsTemplate::factory()->create();
        VpsPackage::factory()->create(['slug' => 'std']);

        $this->actingAs($customer)->get(route('panel.servers.create'))
            ->assertSee('Warszawa')->assertSee('Frankfurt')->assertDontSee('>Test<', false);

        $this->actingAs($customer)->post(route('panel.servers.store'), [
            'package' => 'std', 'template' => $template->id, 'hostname' => 'fra.example.com', 'location' => $fra->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($nodeFra->id, Server::where('hostname', 'fra.example.com')->value('hypervisor_id'));

        // Niewidoczna grupa nie jest do wybrania.
        $this->actingAs($customer)->post(route('panel.servers.store'), [
            'package' => 'std', 'template' => $template->id, 'hostname' => 'x.example.com', 'location' => $hidden->id,
        ])->assertSessionHasErrors('location');
    }

    public function test_wstrzymana_grupa_nie_dostaje_maszyn(): void
    {
        $group = HypervisorGroup::create(['name' => 'Pełna', 'accepts_new_servers' => false]);
        $node = Hypervisor::factory()->create(['hypervisor_group_id' => $group->id]);

        $this->assertFalse($node->fresh()->acceptsMoreServers());

        $this->actingAs($this->admin)->put(route('panel.admin.hypervisor-groups.update', $group), [
            'name' => 'Pełna', 'location' => 'Gdańsk', 'is_public' => 1, 'accepts_new_servers' => 1, 'hypervisor_ids' => [$node->id],
        ])->assertSessionHasNoErrors();

        $group->refresh();
        $this->assertTrue($group->accepts_new_servers);
        $this->assertTrue($group->is_public);
        $this->assertSame('Gdańsk', $group->publicName());
    }

    // --- systemy i wersje --------------------------------------------------------

    public function test_szablon_trafia_do_systemu_swojej_rodziny(): void
    {
        $a = OsTemplate::factory()->create(['name' => 'Ubuntu 24.04 LTS', 'version' => '24.04']);
        $b = OsTemplate::factory()->create(['name' => 'Ubuntu 22.04 LTS', 'version' => '22.04']);

        $this->assertNotNull($a->os_template_group_id);
        $this->assertSame($a->os_template_group_id, $b->os_template_group_id);
        $this->assertSame('Ubuntu', $a->group->name);
        $this->assertSame('24.04 LTS', $a->versionLabel());
    }

    public function test_zamowienie_pokazuje_system_z_wersjami(): void
    {
        Hypervisor::factory()->create();
        OsTemplate::factory()->create(['name' => 'Ubuntu 24.04 LTS', 'version' => '24.04']);
        OsTemplate::factory()->create(['name' => 'Ubuntu 22.04 LTS', 'version' => '22.04']);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->get(route('panel.servers.create'))
            ->assertOk()
            ->assertSee('data-os-version', false)
            ->assertSeeInOrder(['24.04 LTS', '22.04 LTS']);
    }

    public function test_ukryty_system_nie_jest_do_zamowienia(): void
    {
        Hypervisor::factory()->create();
        $template = OsTemplate::factory()->create(['name' => 'Fedora 40', 'family' => 'fedora']);
        $template->group->update(['is_active' => false]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        VpsPackage::factory()->create(['slug' => 'std']);

        $this->actingAs($customer)->get(route('panel.servers.create'))->assertDontSee('Fedora');
        $this->actingAs($customer)->postJson('/api/v1/servers', [
            'package' => 'std', 'template' => $template->id, 'hostname' => 'f.example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('template');
    }

    public function test_admin_zarzadza_systemami_i_wersjami(): void
    {
        $this->actingAs($this->admin)->post(route('panel.admin.template-groups.store'), [
            'name' => 'Debian', 'family' => 'debian', 'sort_order' => 5,
        ])->assertSessionHasNoErrors();
        $group = OsTemplateGroup::where('name', 'Debian')->sole();

        $this->actingAs($this->admin)->post(route('panel.admin.templates.store'), [
            'os_template_group_id' => $group->id, 'virtualization' => 'kvm', 'name' => 'Debian 13',
            'version' => '13', 'image_file' => 'debian-13.qcow2', 'min_disk_gb' => 10,
        ])->assertSessionHasNoErrors();
        $template = OsTemplate::where('name', 'Debian 13')->sole();
        $this->assertSame($group->id, $template->os_template_group_id);
        $this->assertSame('debian', $template->family);

        // System z wersjami nie znika.
        $this->actingAs($this->admin)->delete(route('panel.admin.template-groups.destroy', $group))->assertSessionHasErrors('group');

        // Wersja z maszynami nie znika, bez maszyn — tak.
        $server = Server::factory()->create(['os_template_id' => $template->id]);
        $this->actingAs($this->admin)->delete(route('panel.admin.templates.destroy', $template))->assertSessionHasErrors('template');
        $server->forceDelete();
        $this->actingAs($this->admin)->delete(route('panel.admin.templates.destroy', $template))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->delete(route('panel.admin.template-groups.destroy', $group))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('panel.admin.templates'))->assertOk();
    }
}
