<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\HypervisorSelector;
use App\Domain\Provisioning\Migrations;
use App\Domain\Provisioning\ServerCleanup;
use App\Domain\Provisioning\ServerHardware;
use App\Domain\Provisioning\ServerProvisioner;
use App\Jobs\RunServerActionJob;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerDisk;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServerHardwareTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Hypervisor $node;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->node = Hypervisor::factory()->create(['virtualization' => 'kvm', 'disk_gb_total' => 500, 'disk_gb_used' => 50]);
        $this->server = Server::factory()->create(['hypervisor_id' => $this->node->id, 'disk_gb' => 50]);
    }

    public function test_pakiet_z_dodatkowym_dyskiem_rezerwuje_i_wysyla(): void
    {
        $node = Hypervisor::factory()->create(['virtualization' => 'kvm', 'cpu_cores_total' => 64, 'ram_mb_total' => 262144, 'disk_gb_total' => 4000]);
        $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
        IpAddress::factory()->count(2)->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id]);
        $package = VpsPackage::factory()->create(['disk_gb' => 40, 'extra_disk_gb' => 200, 'ip_count' => 1]);
        $template = OsTemplate::factory()->create(['hw_firmware' => 'uefi', 'hw_tpm' => true]);

        $server = app(ServerProvisioner::class)->order($this->admin, $package, $template, 'vps1.example.com', preferred: $node);
        $this->assertSame(240, $node->fresh()->disk_gb_used);
        $payload = ServerPayload::forCreate($server->fresh());
        $this->assertSame([['slot' => 1, 'size_gb' => 200]], $payload['extra_disks']);
        $this->assertSame(['disk_bus' => 'virtio', 'io_mode' => 'native', 'firmware' => 'uefi', 'tpm' => true, 'cpu_model' => null, 'cpuset' => null, 'hyperv' => 'off'], $payload['hardware']);

        app(HypervisorSelector::class)->recalculate($node);
        $this->assertSame(240, $node->fresh()->disk_gb_used, 'Przeliczenie liczy też dodatkowe dyski');
        app(ServerCleanup::class)->finalise($server->fresh());
        $this->assertSame(0, $node->fresh()->disk_gb_used);
        $this->assertSame(0, ServerDisk::count());
    }

    public function test_dodawanie_powiekszanie_usuwanie_dyskow(): void
    {
        $this->actingAs($this->admin)->post(route('panel.servers.disks.store', $this->server), ['size_gb' => 100])->assertSessionHasNoErrors();
        $this->assertSame(150, $this->node->fresh()->disk_gb_used);
        $disk = ServerDisk::firstOrFail();
        $this->assertSame('vdb', $disk->dev());

        $this->put(route('panel.servers.disks.update', [$this->server, $disk]), ['size_gb' => 150])->assertSessionHasNoErrors();
        $this->put(route('panel.servers.disks.primary', $this->server), ['size_gb' => 80])->assertSessionHasNoErrors();
        $this->assertSame(80, $this->server->fresh()->disk_gb);
        $this->assertSame(230, $this->node->fresh()->disk_gb_used);
        $this->put(route('panel.servers.disks.primary', $this->server), ['size_gb' => 60])->assertSessionHasErrors('hardware');
        $this->post(route('panel.servers.disks.store', $this->server), ['size_gb' => 400])->assertSessionHasErrors('hardware');

        Http::fake(['*' => Http::response(['job_id' => 'd1', 'status' => 'queued'], 202)]);
        $job = ServerJob::where('action', 'disks')->latest('id')->firstOrFail();
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), "/vm/{$this->server->agent_uuid}/disks")
            && $r['disks'] === [['slot' => 1, 'size_gb' => 150]] && $r['primary_gb'] === 80 && $r['delete_removed'] === false);

        $this->delete(route('panel.servers.disks.destroy', [$this->server, $disk]))->assertSessionHasErrors('confirm');
        $this->delete(route('panel.servers.disks.destroy', [$this->server, $disk]), ['confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0, ServerDisk::count());
        $this->assertSame(80, $this->node->fresh()->disk_gb_used);
        $this->assertTrue(ServerJob::where('action', 'disks')->latest('id')->first()->payload['delete_removed']);

        $this->actingAs($this->server->user)->post(route('panel.servers.disks.store', $this->server), ['size_gb' => 10])->assertForbidden();
    }

    public function test_odrzucenie_przez_wezel_cofa_zmiane(): void
    {
        $this->actingAs($this->admin)->post(route('panel.servers.disks.store', $this->server), ['size_gb' => 100]);
        $this->assertSame(150, $this->node->fresh()->disk_gb_used);
        $job = ServerJob::where('action', 'disks')->firstOrFail();
        $job->forceFill(['status' => ServerJob::STATUS_RUNNING, 'agent_job_id' => 'x'])->save();
        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'failed', 'error' => 'Brak miejsca w magazynie']);

        $this->assertSame(0, ServerDisk::count());
        $this->assertSame(50, $this->node->fresh()->disk_gb_used);

        // Dodatkowe dyski blokują migrację (przenosi tylko dysk główny).
        $this->server->disks()->create(['slot' => 1, 'size_gb' => 10]);
        $this->expectExceptionMessage(__('Maszyna ma dodatkowe dyski — migracja przenosi tylko dysk główny. Usuń dodatkowe dyski albo przenieś dane przed migracją.'));
        app(Migrations::class)->assertMigratable($this->server->fresh(), Hypervisor::factory()->create(['virtualization' => 'kvm']), 'cold');
    }

    public function test_dziedziczenie_i_zmiany_sprzetu(): void
    {
        $this->node->forceFill(['hw_io_mode' => 'io_uring', 'hw_cpu_model' => 'EPYC-Milan', 'hw_cpuset' => '2-31'])->save();
        $hw = ServerHardware::resolve($this->server->fresh());
        $this->assertSame(['value' => 'io_uring', 'source' => 'hypervisor'], $hw['io_mode']);
        $this->assertSame(['value' => '2-31', 'source' => 'hypervisor'], $hw['cpuset']);
        $this->assertSame(['value' => 'bios', 'source' => 'default'], $hw['firmware']);

        // Klient: tylko rozruch; pozostałe pola są ignorowane.
        $customer = $this->server->user;
        $this->actingAs($customer)->put(route('panel.servers.hardware', $this->server), ['hw_firmware' => 'uefi', 'hw_cpu_model' => 'host-model'])->assertSessionHasNoErrors();
        $this->assertSame('uefi', $this->server->fresh()->hw_firmware);
        $this->assertNull($this->server->fresh()->hw_cpu_model);
        $this->get(route('panel.servers.show', $this->server))->assertOk()->assertSee('Dyski i sprzęt')->assertDontSee('Magistrala dysków');

        // vTPM bez UEFI — odmowa.
        $this->actingAs($this->admin)->put(route('panel.servers.hardware', $this->server), ['hw_firmware' => 'bios', 'hw_tpm' => '1'])->assertSessionHasErrors('hardware');
        $this->put(route('panel.servers.hardware', $this->server), ['hw_firmware' => 'uefi', 'hw_tpm' => '1', 'hw_disk_bus' => 'scsi'])->assertSessionHasNoErrors();
        $this->get(route('panel.servers.show', $this->server))->assertSee('Magistrala dysków')->assertSee('EPYC-Milan');

        Http::fake(['*' => Http::response(['job_id' => 'h1', 'status' => 'queued'], 202)]);
        $job = ServerJob::where('action', 'hardware')->latest('id')->firstOrFail();
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/hardware') && $r['hardware'] === [
            'disk_bus' => 'scsi', 'io_mode' => 'io_uring', 'firmware' => 'uefi', 'tpm' => true, 'cpu_model' => 'EPYC-Milan', 'cpuset' => '2-31', 'hyperv' => 'off']);
    }

    public function test_api_administracji_i_formularze(): void
    {
        Sanctum::actingAs($this->admin, ['admin']);
        $this->postJson("/api/v1/admin/servers/{$this->server->id}/disks", ['size_gb' => 20])->assertStatus(202)->assertJsonPath('data.1.dev', 'vdb');
        $this->putJson("/api/v1/admin/servers/{$this->server->id}/disks/vdb", ['size_gb' => 30])->assertStatus(202)->assertJsonPath('data.1.size_gb', 30);
        $this->putJson("/api/v1/admin/servers/{$this->server->id}/disks/vdb", ['size_gb' => 10])->assertStatus(409);
        $this->deleteJson("/api/v1/admin/servers/{$this->server->id}/disks/vda")->assertStatus(422);
        $this->deleteJson("/api/v1/admin/servers/{$this->server->id}/disks/vdb")->assertStatus(202)->assertJsonCount(1, 'data');
        $this->putJson("/api/v1/admin/servers/{$this->server->id}/hardware", ['firmware' => 'uefi', 'tpm' => true])->assertStatus(202)
            ->assertJsonPath('data.tpm.value', true)->assertJsonPath('data.firmware.source', 'server');
        $this->getJson("/api/v1/admin/servers/{$this->server->id}/hardware")->assertOk()->assertJsonPath('data.disk_bus.value', 'virtio');

        $this->actingAs($this->admin)->put(route('panel.admin.hypervisors.update', $this->node), [
            'cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 500, 'bridge' => 'br0', 'status' => 'online',
            'hw_disk_bus' => 'scsi', 'hw_cpuset' => '0-3;reboot',
        ])->assertSessionHasErrors('hw_cpuset');
        $this->put(route('panel.admin.hypervisors.update', $this->node), [
            'cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 500, 'bridge' => 'br0', 'status' => 'online',
            'hw_disk_bus' => 'scsi', 'hw_cpuset' => '2-31,34-63', 'hw_io_mode' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame('scsi', $this->node->fresh()->hw_disk_bus);
        $this->assertNull($this->node->fresh()->hw_io_mode);
        $this->get(route('panel.admin.hypervisors.show', $this->node))->assertOk()->assertSee('Rdzenie hosta dla maszyn');

        $template = $this->server->template;
        $this->put(route('panel.admin.templates.update', $template), ['name' => 'Win', 'version' => '11', 'min_disk_gb' => 40, 'hw_tpm' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($template->fresh()->hw_tpm);
        $this->assertSame('uefi', $template->fresh()->hw_firmware, 'vTPM wymusza UEFI');
    }
}
