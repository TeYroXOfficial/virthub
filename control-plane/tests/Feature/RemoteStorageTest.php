<?php

namespace Tests\Feature;

use App\Domain\Provisioning\Migrations;
use App\Domain\Provisioning\ServerProvisioner;
use App\Models\Datastore;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Zdalne magazyny: NFS, SMB, CephFS, iSCSI (montowane przez agenta), StorPool, Lightbits. */
class RemoteStorageTest extends TestCase
{
    use RefreshDatabase;

    private const JWT = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ2aCJ9.c2lnbmF0dXJl';

    private User $admin;

    private Hypervisor $node;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->node = Hypervisor::factory()->create(['virtualization' => 'kvm']);
        Http::fake(['*/system/datastores' => fn (Request $r) => Http::response(['datastores' => array_map(
            fn ($d) => ['id' => $d['id'], 'ok' => true, 'total_gb' => 500, 'free_gb' => 200], $r['datastores'])])]);
    }

    private function store(string $type, array $config, array $extra = []): Datastore
    {
        $this->actingAs($this->admin)->post(route('panel.admin.datastores.store'), [
            'name' => 'DS '.$type, 'type' => $type, 'is_active' => 1, 'thin' => 1, 'hypervisors' => [$this->node->id], 'config' => $config,
        ] + $extra)->assertSessionHasNoErrors();

        return Datastore::latest('id')->firstOrFail();
    }

    public function test_nfs_i_smb_trafiaja_do_agenta_z_haslem_szyfrowanym(): void
    {
        $nfs = $this->store('nfs', ['server' => '10.0.0.5', 'export' => '/srv/vms', 'options' => 'vers=4.2,nconnect=4']);
        $this->assertSame(['id' => $nfs->id, 'type' => 'nfs', 'thin' => true, 'server' => '10.0.0.5', 'export' => '/srv/vms', 'options' => 'vers=4.2,nconnect=4'],
            $nfs->toAgentPayload());
        $this->assertTrue($nfs->isFileBased());
        $this->assertTrue($nfs->isShared());
        $this->assertSame('10.0.0.5:/srv/vms', $nfs->describe());

        $smb = $this->store('cifs', ['server' => 'nas.local', 'export' => 'virthub', 'username' => 'vh', 'password' => 'S3kret!']);
        $this->assertSame('S3kret!', $smb->toAgentPayload()['password']);
        $this->assertStringNotContainsString('S3kret!', (string) DB::table('datastores')->where('id', $smb->id)->value('config'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/system/datastores') && ($r['datastores'][0]['password'] ?? null) === 'S3kret!');

        // Edycja bez hasła zostawia stare.
        $this->put(route('panel.admin.datastores.update', $smb), [
            'name' => 'SMB', 'is_active' => 1, 'hypervisors' => [$this->node->id], 'config' => ['server' => 'nas2.local', 'export' => 'vh', 'password' => ''],
        ])->assertSessionHasNoErrors();
        $this->assertSame('S3kret!', $smb->fresh()->config['password']);
        $this->assertSame('nas2.local', $smb->fresh()->config['server']);

        $this->get(route('panel.admin.datastores'))->assertOk()->assertSee('SMB / CIFS')->assertSee('//nas2.local/vh')->assertDontSee('S3kret!');
        $this->get(route('panel.admin.datastores', ['type' => 'iscsi']))->assertOk()->assertSee('IQN');
    }

    public function test_walidacja_typow(): void
    {
        $this->actingAs($this->admin);
        $post = fn (string $type, array $config) => $this->post(route('panel.admin.datastores.store'), ['name' => 'X', 'type' => $type, 'config' => $config]);
        $post('nfs', ['server' => 'nas; reboot', 'export' => '/vms'])->assertSessionHasErrors('config.server');
        $post('nfs', ['server' => 'nas', 'export' => '/srv/../etc'])->assertSessionHasErrors('config.export');
        $post('nfs', ['server' => 'nas', 'export' => '/vms', 'options' => 'ro;rm'])->assertSessionHasErrors('config.options');
        $post('iscsi', ['portal' => '10.0.0.7', 'iqn' => 'nie-iqn'])->assertSessionHasErrors('config.iqn');
        $post('cephfs', ['monitors' => '10.0.0.1'])->assertSessionHasErrors('config.key');
        $post('lightbits', ['endpoint' => '10.0.0.9:443', 'nqn' => 'nqn.2016-01.com.lightbitslabs:uuid:x', 'targets' => 'zły adres!', 'jwt' => self::JWT])
            ->assertSessionHasErrors('config.targets');
        $post('storpool', [])->assertSessionHasErrors('config.template');
        $this->assertSame(0, Datastore::count());
    }

    public function test_iscsi_cephfs_i_lightbits_ladunek(): void
    {
        $iscsi = $this->store('iscsi', ['portal' => '10.0.0.7:3260', 'iqn' => 'iqn.2026-01.pl.example:vms', 'lun' => 2, 'username' => 'chap', 'password' => 'chapsecret12']);
        $this->assertSame(['id' => $iscsi->id, 'type' => 'iscsi', 'thin' => true, 'lun' => 2, 'portal' => '10.0.0.7:3260', 'iqn' => 'iqn.2026-01.pl.example:vms',
            'username' => 'chap', 'password' => 'chapsecret12'], $iscsi->toAgentPayload());

        $cephfs = $this->store('cephfs', ['monitors' => '10.0.0.1, 10.0.0.2', 'export' => '/virthub', 'user' => 'vh', 'key' => 'AQBzZWNyZXRrZXlrZXlrZXlrZXlrZXk9PQ==']);
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $cephfs->toAgentPayload()['monitors']);
        $this->assertSame('/virthub', $cephfs->toAgentPayload()['export']);

        $lb = $this->store('lightbits', ['endpoint' => '10.0.0.9:443', 'nqn' => 'nqn.2016-01.com.lightbitslabs:uuid:abc', 'targets' => '10.0.0.9:4420, 10.0.0.10:4420',
            'project' => 'vh', 'replicas' => 3, 'jwt' => self::JWT]);
        $this->assertEquals(['id' => $lb->id, 'type' => 'lightbits', 'thin' => true, 'targets' => ['10.0.0.9:4420', '10.0.0.10:4420'], 'replicas' => 3,
            'nqn' => 'nqn.2016-01.com.lightbitslabs:uuid:abc', 'endpoint' => '10.0.0.9:443', 'jwt' => self::JWT, 'project' => 'vh'], $lb->toAgentPayload());
        $this->assertFalse($lb->isFileBased());
        $this->assertTrue($lb->isExperimental());
        $this->get(route('panel.admin.datastores'))->assertOk()->assertSee('Lightbits NVMe/TCP')->assertSee('eksperymentalny')->assertDontSee(self::JWT);
    }

    public function test_magazyn_blokowy_blokuje_elevate_i_migracje_a_montowany_nie(): void
    {
        Queue::fake();
        $storpool = $this->store('storpool', ['template' => 'nvme']);
        $this->assertSame(['id' => $storpool->id, 'type' => 'storpool', 'thin' => true, 'template' => 'nvme'], $storpool->toAgentPayload());
        $server = Server::factory()->create(['hypervisor_id' => $this->node->id, 'datastore_id' => $storpool->id, 'agent_uuid' => '11111111-2222-3333-4444-555555555555']);

        try {
            app(ServerProvisioner::class)->elevate($server, 'start', $this->admin);
            $this->fail('Elevate na StorPool powinien być odrzucony');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('StorPool', $e->getMessage());
        }
        $other = Hypervisor::factory()->create(['virtualization' => 'kvm']);
        try {
            app(Migrations::class)->assertMigratable($server, $other, 'cold');
            $this->fail('Migracja dysku StorPool powinna być odrzucona');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('StorPool', $e->getMessage());
        }

        $nfs = $this->store('nfs', ['server' => '10.0.0.5', 'export' => '/vms']);
        $server->forceFill(['datastore_id' => $nfs->id])->save();
        $job = app(ServerProvisioner::class)->elevate($server->fresh(), 'start', $this->admin);
        $this->assertSame('elevate', $job->action);
    }
}
