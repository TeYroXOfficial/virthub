<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Domain\Provisioning\NoCapacityException;
use App\Domain\Provisioning\RemoteBackups;
use App\Domain\Provisioning\ServerProvisioner;
use App\Domain\Storage\Datastores;
use App\Models\BackupTarget;
use App\Models\Datastore;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DatastoresTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'AQBzZWNyZXRrZXlrZXlrZXlrZXlrZXk9PQ==';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function node(string $name): Hypervisor
    {
        $node = Hypervisor::factory()->create(['name' => $name, 'virtualization' => 'kvm', 'cpu_cores_total' => 64, 'ram_mb_total' => 262144, 'disk_gb_total' => 4000]);
        $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
        IpAddress::factory()->count(3)->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id]);

        return $node;
    }

    /** Raport magazynów zwracany przez udawany węzeł; null = każdy zapytany magazyn działa. */
    private ?array $report = null;

    private function fakeNode(): void
    {
        Http::fake(['*/system/datastores' => fn (Request $r) => Http::response(['datastores' => $this->report
            ?? array_map(fn ($d) => ['id' => $d['id'], 'ok' => true, 'total_gb' => 100, 'free_gb' => 50], $r['datastores'])])]);
    }

    private function ceph(array $nodes): Datastore
    {
        $this->fakeNode();
        $this->actingAs($this->admin)->post(route('panel.admin.datastores.store'), [
            'name' => 'Ceph NVMe', 'type' => 'ceph', 'is_active' => 1, 'hypervisors' => array_map(fn ($n) => $n->id, $nodes),
            'config' => ['monitors' => '10.0.0.1, 10.0.0.2:3300 [2001:db8::5]:6789', 'pool' => 'vms', 'user' => 'libvirt', 'key' => self::KEY],
        ])->assertSessionHasNoErrors()->assertRedirect(route('panel.admin.datastores'));

        return Datastore::latest('id')->firstOrFail();
    }

    public function test_dodanie_ceph_szyfruje_klucz_i_odczytuje_pojemnosc(): void
    {
        $node = $this->node('kvm1');
        $datastore = $this->ceph([$node]);

        $this->assertSame(['10.0.0.1', '10.0.0.2:3300', '[2001:db8::5]:6789'], $datastore->config['monitors']);
        $this->assertStringNotContainsString(self::KEY, (string) DB::table('datastores')->value('config'));
        $this->assertSame([
            'id' => $datastore->id, 'type' => 'ceph', 'thin' => true, 'pool' => 'vms',
            'monitors' => ['10.0.0.1', '10.0.0.2:3300', '[2001:db8::5]:6789'], 'user' => 'libvirt', 'key' => self::KEY,
        ], $datastore->toAgentPayload());

        $this->report = [['id' => $datastore->id, 'ok' => true, 'total_gb' => 1000, 'free_gb' => 700]];
        $this->post(route('panel.admin.datastores.refresh', $datastore))->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/system/datastores') && $r['datastores'][0]['key'] === self::KEY);
        $pivot = $datastore->hypervisors()->firstOrFail()->pivot;
        $this->assertTrue((bool) $pivot->ok);
        $this->assertSame(700, (int) $pivot->free_gb);
        $this->assertSame(['total_gb' => 1000, 'free_gb' => 700], array_slice($datastore->fresh()->load('hypervisors')->capacity(), 0, 2));

        $this->get(route('panel.admin.datastores'))->assertOk()->assertSee('Ceph NVMe')->assertSee('700')->assertDontSee(self::KEY);

        // Edycja bez klucza zostawia stary.
        $this->put(route('panel.admin.datastores.update', $datastore), [
            'name' => 'Ceph', 'is_active' => 1, 'hypervisors' => [$node->id],
            'config' => ['monitors' => '10.0.0.9', 'pool' => 'vms2', 'user' => 'libvirt', 'key' => ''],
        ])->assertSessionHasNoErrors();
        $this->assertSame(self::KEY, $datastore->fresh()->config['key']);
        $this->assertSame('vms2', $datastore->fresh()->config['pool']);
    }

    public function test_bledy_walidacji_i_blad_wezla(): void
    {
        $node = $this->node('kvm1');
        $this->actingAs($this->admin)->post(route('panel.admin.datastores.store'), [
            'name' => 'X', 'type' => 'ceph', 'config' => ['monitors' => 'zły monitor!', 'pool' => 'vms', 'key' => self::KEY],
        ])->assertSessionHasErrors('config.monitors');
        $this->post(route('panel.admin.datastores.store'), [
            'name' => 'X', 'type' => 'ceph', 'config' => ['monitors' => '10.0.0.1', 'pool' => 'vms', 'key' => 'nie base64!'],
        ])->assertSessionHasErrors('config.key');
        $this->post(route('panel.admin.datastores.store'), [
            'name' => 'X', 'type' => 'directory', 'config' => ['path' => '/srv/../etc'],
        ])->assertSessionHasErrors('config.path');

        $this->report = [['id' => 1, 'ok' => false, 'error' => 'Brak katalogu']];
        $this->fakeNode();
        $this->post(route('panel.admin.datastores.store'), [
            'name' => 'NVMe', 'type' => 'directory', 'thin' => 0, 'is_active' => 1, 'hypervisors' => [$node->id], 'config' => ['path' => '/srv/nvme/'],
        ])->assertSessionHasNoErrors();
        $datastore = Datastore::firstOrFail();
        $this->assertFalse($datastore->thin);
        $this->assertSame('/srv/nvme', $datastore->config['path']);
        $this->assertSame(['id' => $datastore->id, 'type' => 'directory', 'thin' => false, 'path' => '/srv/nvme'], $datastore->toAgentPayload());
        $this->assertSame('Brak katalogu', $datastore->hypervisors()->firstOrFail()->pivot->error);

        $this->report = [];
        $this->assertSame(0, app(Datastores::class)->refresh($datastore->load('hypervisors')));
        $this->assertFalse((bool) $datastore->hypervisors()->firstOrFail()->pivot->ok);
        $this->assertSame('Węzeł nie zwrócił raportu magazynu.', $datastore->hypervisors()->firstOrFail()->pivot->error);
    }

    public function test_pakiet_z_magazynem_wybiera_wezel_i_wysyla_storage(): void
    {
        Queue::fake();
        $without = $this->node('bez-cepha');
        $with = $this->node('z-cephem');
        // Bin-packing wybrałby bardziej zajęty węzeł — ten bez magazynu.
        $without->forceFill(['cpu_cores_used' => 40])->save();
        $datastore = $this->ceph([$with]);
        $package = VpsPackage::factory()->create(['datastore_id' => $datastore->id, 'ip_count' => 1]);
        $template = OsTemplate::factory()->create();

        $server = app(ServerProvisioner::class)->order($this->admin, $package, $template, 'vps1.example.com');

        $this->assertSame($with->id, $server->hypervisor_id);
        $this->assertSame($datastore->id, $server->datastore_id);
        $payload = ServerPayload::forCreate($server->fresh());
        $this->assertSame('ceph', $payload['storage']['type']);
        $this->assertSame('vms', $payload['storage']['pool']);

        // Węzeł, na którym odczyt magazynu się nie udał (np. stary agent), nie jest wybierany.
        $datastore->hypervisors()->updateExistingPivot($with->id, ['ok' => false]);
        try {
            app(ServerProvisioner::class)->order($this->admin, $package, $template, 'vps-x.example.com');
            $this->fail('Węzeł z błędem odczytu magazynu nie powinien przyjąć maszyny');
        } catch (NoCapacityException) {
        }

        // Węzeł wskazany ręcznie bez magazynu — jasny błąd.
        $this->expectException(NoCapacityException::class);
        app(ServerProvisioner::class)->order($this->admin, $package, $template, 'vps2.example.com', preferred: $without);
    }

    public function test_bez_magazynu_ladunek_bez_storage_i_wylaczony_magazyn(): void
    {
        Queue::fake();
        $node = $this->node('kvm1');
        $server = Server::factory()->create(['hypervisor_id' => $node->id]);
        $this->assertArrayNotHasKey('storage', ServerPayload::forCreate($server));

        $datastore = $this->ceph([$node]);
        $datastore->update(['is_active' => false]);
        $package = VpsPackage::factory()->create(['datastore_id' => $datastore->id]);
        $this->expectException(NoCapacityException::class);
        app(ServerProvisioner::class)->order($this->admin, $package, OsTemplate::factory()->create(), 'vps3.example.com');
    }

    public function test_ceph_blokuje_elevate_i_kopie_oraz_usuwanie_magazynu(): void
    {
        Queue::fake();
        $node = $this->node('kvm1');
        $datastore = $this->ceph([$node]);
        $server = Server::factory()->create(['hypervisor_id' => $node->id, 'datastore_id' => $datastore->id, 'agent_uuid' => '11111111-2222-3333-4444-555555555555']);

        try {
            app(ServerProvisioner::class)->elevate($server, 'start', $this->admin);
            $this->fail('Elevate na Cephie powinien być odrzucony');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Ceph', $e->getMessage());
        }
        $target = BackupTarget::create(['name' => 'Lokalnie', 'type' => 'local', 'config' => ['path' => '/var/backups']]);
        try {
            app(RemoteBackups::class)->create($server, $target, actor: $this->admin);
            $this->fail('Kopia na cel zewnętrzny z dysku w Cephie powinna być odrzucona');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Ceph', $e->getMessage());
        }

        $this->actingAs($this->admin)->delete(route('panel.admin.datastores.destroy', $datastore))->assertSessionHasErrors('datastore');
        $this->put(route('panel.admin.datastores.update', $datastore), [
            'name' => 'Ceph', 'is_active' => 1, 'hypervisors' => [],
            'config' => ['monitors' => '10.0.0.1', 'pool' => 'vms', 'user' => 'libvirt'],
        ])->assertSessionHasErrors('hypervisors');

        $this->get(route('panel.servers.show', $server))->assertOk()->assertSee('magazyn Ceph NVMe');

        $server->delete();
        $this->delete(route('panel.admin.datastores.destroy', $datastore))->assertSessionHasNoErrors();
        $this->assertModelMissing($datastore);
    }

    public function test_pakiet_zapisuje_magazyn_i_klient_nie_ma_dostepu(): void
    {
        $datastore = Datastore::create(['name' => 'NVMe', 'type' => 'directory', 'config' => ['path' => '/srv/nvme']]);
        $package = VpsPackage::factory()->create();
        $this->actingAs($this->admin)->put(route('panel.admin.packages.update', $package), [
            'name' => $package->name, 'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 20, 'bandwidth_gb' => 1000, 'ip_count' => 1,
            'datastore_id' => $datastore->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($datastore->id, $package->fresh()->datastore_id);
        $this->get(route('panel.admin.packages'))->assertOk()->assertSee('NVMe');

        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('panel.admin.datastores'))->assertForbidden();
    }
}
