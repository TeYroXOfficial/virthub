<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\PciAssets;
use App\Domain\Provisioning\ServerCleanup;
use App\Jobs\RunServerActionJob;
use App\Models\Hypervisor;
use App\Models\PciAsset;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PciAssetsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Hypervisor $node;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->node = Hypervisor::factory()->create(['virtualization' => 'kvm']);
    }

    private function asset(string $address, string $type = 'gpu'): PciAsset
    {
        return $this->node->pciAssets()->create(['name' => 'RTX '.$address, 'type' => $type, 'pci_address' => $address]);
    }

    public function test_skan_i_rejestracja(): void
    {
        Http::fake(['*/system/pci' => Http::response(['iommu' => true, 'devices' => [
            ['address' => '0000:41:00.0', 'vendor_id' => '10de', 'device_id' => '2204', 'class' => '0300', 'type' => 'vga', 'driver' => 'vfio-pci', 'iommu_group' => 31, 'name' => 'NVIDIA RTX 3090'],
        ]])]);

        $this->actingAs($this->admin)->get(route('panel.admin.pci', $this->node))->assertOk()->assertSee('NVIDIA RTX 3090')->assertSee(__('IOMMU włączone'));
        $this->post(route('panel.admin.pci.store', $this->node), ['pci_address' => '0000:41:00.0', 'name' => 'NVIDIA RTX 3090', 'type' => 'gpu', 'iommu_group' => 31])
            ->assertSessionHasNoErrors();
        $this->post(route('panel.admin.pci.store', $this->node), ['pci_address' => '0000:41:00.0', 'name' => 'x', 'type' => 'gpu'])->assertSessionHasErrors('pci_address');
        $this->post(route('panel.admin.pci.store', $this->node), ['pci_address' => '41:00.0', 'name' => 'x', 'type' => 'gpu'])->assertSessionHasErrors('pci_address');
        $this->assertSame(31, PciAsset::firstOrFail()->iommu_group);

        $this->actingAs(User::factory()->create())->get(route('panel.admin.pci', $this->node))->assertForbidden();
    }

    public function test_przypisanie_wysyla_hostdevs(): void
    {
        $server = Server::factory()->create(['hypervisor_id' => $this->node->id]);
        $asset = $this->asset('0000:41:00.0');
        $asset->forceFill(['custom_xml' => null])->save();

        $this->actingAs($this->admin)->put(route('panel.admin.pci.update', $asset), ['name' => 'GPU', 'type' => 'gpu', 'server_id' => $server->id, 'restart' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame($server->id, $asset->fresh()->server_id);
        $job = ServerJob::where('action', 'hostdevs')->firstOrFail();

        Http::fake(['*' => Http::response(['job_id' => 'a1', 'status' => 'queued'], 202)]);
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), "/vm/{$server->agent_uuid}/hostdevs")
            && $r['devices'] === [['address' => '0000:41:00.0']] && $r['restart'] === true);

        $this->get(route('panel.servers.show', $server))->assertSee('0000:41:00.0');

        // Odpięcie.
        $this->put(route('panel.admin.pci.update', $asset), ['name' => 'GPU', 'type' => 'gpu', 'server_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($asset->fresh()->server_id);
        $this->assertSame(2, ServerJob::where('action', 'hostdevs')->count());
    }

    public function test_urzadzenie_innego_wezla_i_zajete(): void
    {
        $server = Server::factory()->create(['hypervisor_id' => $this->node->id]);
        $other = Server::factory()->create(['hypervisor_id' => $this->node->id]);
        $foreign = Server::factory()->create();
        $asset = $this->asset('0000:41:00.0');

        $this->actingAs($this->admin)->put(route('panel.admin.pci.update', $asset), ['name' => 'GPU', 'type' => 'gpu', 'server_id' => $foreign->id])
            ->assertSessionHasErrors('server_id');

        app(PciAssets::class)->assign($server, [$asset->id]);
        $this->expectException(ValidationException::class);
        app(PciAssets::class)->assign($other, [$asset->id]);
    }

    public function test_xml_wlasny_walidowany(): void
    {
        $asset = $this->asset('0000:41:00.0');
        $this->actingAs($this->admin)->put(route('panel.admin.pci.update', $asset), ['name' => 'GPU', 'type' => 'gpu', 'custom_xml' => '<disk/>'])
            ->assertSessionHasErrors('custom_xml');
        $this->put(route('panel.admin.pci.update', $asset), ['name' => 'GPU', 'type' => 'gpu', 'custom_xml' => "<hostdev mode='subsystem' type='pci' managed='yes'><source><address domain='0x0000' bus='0x41' slot='0x00' function='0x0'/></source><rom bar='off'/></hostdev>"])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('rom', $asset->fresh()->custom_xml);
    }

    public function test_pakiet_z_gpu_przydziela_po_utworzeniu(): void
    {
        $package = VpsPackage::factory()->create(['pci_type' => 'gpu', 'pci_count' => 1]);
        $server = Server::factory()->building()->create(['hypervisor_id' => $this->node->id, 'vps_package_id' => $package->id]);
        $this->asset('0000:41:00.0', 'nic');
        $gpu = $this->asset('0000:42:00.0');
        $job = ServerJob::create(['server_id' => $server->id, 'hypervisor_id' => $this->node->id, 'action' => 'create', 'status' => ServerJob::STATUS_RUNNING, 'agent_job_id' => 'j']);

        app(AgentResultApplier::class)->apply($job, ['status' => 'done', 'result' => ['uuid' => '11111111-2222-3333-4444-555555555555', 'state' => 'running']]);

        $this->assertSame($server->id, $gpu->fresh()->server_id);
        $this->assertSame(['restart' => true], ServerJob::where('action', 'hostdevs')->firstOrFail()->payload);
    }

    public function test_usuniecie_maszyny_zwalnia_urzadzenia(): void
    {
        $server = Server::factory()->create(['hypervisor_id' => $this->node->id]);
        $asset = $this->asset('0000:41:00.0');
        $asset->forceFill(['server_id' => $server->id])->save();
        Http::fake();

        app(ServerCleanup::class)->finalise($server);
        $this->assertNull($asset->fresh()->server_id);
    }
}
