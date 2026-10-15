<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\RemoteBackups;
use App\Domain\Provisioning\ServerProvisioner;
use App\Jobs\RunServerActionJob;
use App\Models\BackupTarget;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RescueAndGuestSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->server = Server::factory()->create(['user_id' => $this->owner->id]);
        $pool = IpPool::factory()->create(['hypervisor_id' => $this->server->hypervisor_id]);
        IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $this->server->hypervisor_id, 'server_id' => $this->server->id, 'is_primary' => true]);
    }

    private function finish(string $action, array $result): void
    {
        $job = ServerJob::where('action', $action)->latest('id')->firstOrFail();
        $job->forceFill(['status' => ServerJob::STATUS_RUNNING, 'agent_job_id' => 'x'])->save();
        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'done', 'result' => $result]);
    }

    public function test_tryb_ratunkowy_od_startu_do_konca(): void
    {
        // Bez aktywnego szablonu Debian/Ubuntu ani oznaczonego jako ratunkowy — tryb niedostępny.
        OsTemplate::query()->update(['is_active' => false]);
        $this->actingAs($this->owner)->post(route('panel.servers.rescue.start', $this->server))->assertSessionHasErrors('guest');
        $rescue = OsTemplate::factory()->create(['name' => 'Debian 13 Rescue', 'family' => 'debian', 'virtualization' => 'kvm', 'image_file' => 'debian-13.qcow2', 'is_rescue' => true]);

        $this->post(route('panel.servers.rescue.start', $this->server), ['ssh_key' => 'zły klucz'])->assertSessionHasErrors('ssh_key');
        $this->post(route('panel.servers.rescue.start', $this->server))->assertSessionHasNoErrors();
        $password = $this->server->fresh()->rescue_password;
        $this->assertNotEmpty($password);

        Http::fake(['*' => Http::response(['job_id' => 'r1', 'status' => 'queued'], 202)]);
        $job = ServerJob::where('action', 'rescue')->firstOrFail();
        $this->assertArrayNotHasKey('root_password', $job->payload, 'Hasło nie leży w kolejce panelu');
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/rescue') && $r['action'] === 'start'
            && $r['template'] === 'debian-13.qcow2' && $r['root_password'] === $password && $r['interfaces'][0]['address'] === $this->server->primaryIp()->address);

        $this->finish('rescue', ['rescue' => true, 'state' => 'running']);
        $this->assertTrue($this->server->fresh()->inRescue());
        $this->get(route('panel.servers.show', $this->server))->assertOk()->assertSee($password)->assertSee('/dev/vdi');

        // W trybie ratunkowym nie ma reinstalacji ani kopii.
        try {
            app(ServerProvisioner::class)->rebuild($this->server->fresh(), $rescue);
            $this->fail('Reinstalacja w trybie ratunkowym');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ratunkowym', $e->getMessage());
        }
        $target = BackupTarget::create(['name' => 'L', 'type' => 'local', 'config' => ['path' => '/srv/k']]);
        $this->expectExceptionMessage(__('Maszyna jest w trybie ratunkowym — najpierw go zakończ.'));
        try {
            app(RemoteBackups::class)->create($this->server->fresh(), $target);
        } finally {
            $this->delete(route('panel.servers.rescue.stop', $this->server))->assertSessionHasNoErrors();
            $this->finish('rescue', ['rescue' => false, 'state' => 'running']);
            $this->assertFalse($this->server->fresh()->inRescue());
            $this->assertNull($this->server->fresh()->rescue_password);
        }
    }

    public function test_dysk_konfiguracji_i_nazwa_hosta(): void
    {
        $this->actingAs($this->owner)->put(route('panel.servers.cloud-init', $this->server), ['enabled' => 0])->assertSessionHasNoErrors();
        $this->finish('seed', ['enabled' => false]);
        $this->assertTrue($this->server->fresh()->cloud_init_disabled);
        $this->get(route('panel.servers.show', $this->server))->assertSee('odłączony');

        $this->put(route('panel.servers.hostname', $this->server), ['hostname' => 'zła nazwa'])->assertSessionHasErrors('guest');
        $this->put(route('panel.servers.hostname', $this->server), ['hostname' => 'Web1.Example.com'])->assertSessionHasNoErrors();
        $this->assertSame('web1.example.com', $this->server->fresh()->hostname);
        Http::fake(['*' => Http::response(['job_id' => 'h1', 'status' => 'queued'], 202)]);
        (new RunServerActionJob(ServerJob::where('action', 'hostname')->firstOrFail()->id))->handle();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/hostname') && $r['hostname'] === 'web1.example.com' && $r['os_type'] === 'linux');

        Sanctum::actingAs($this->owner, ['servers:write']);
        $this->putJson("/api/v1/servers/{$this->server->id}/hostname", ['hostname' => 'api.example.com'])->assertOk()->assertJsonPath('hostname', 'api.example.com');
        $this->putJson("/api/v1/servers/{$this->server->id}/cloud-init", ['enabled' => true])->assertStatus(202);
        OsTemplate::query()->update(['is_active' => false]);
        $this->getJson("/api/v1/servers/{$this->server->id}/rescue")->assertOk()->assertJsonPath('active', false)->assertJsonPath('password', null);
        $this->postJson("/api/v1/servers/{$this->server->id}/rescue")->assertStatus(409);
    }

    public function test_wybor_adresu_ipv6(): void
    {
        $pool = IpPool::factory()->create(['hypervisor_id' => $this->server->hypervisor_id, 'version' => 6, 'cidr' => '2001:db8:10::/64', 'gateway' => '2001:db8:10::1', 'prefix' => 64]);
        $v6 = IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $this->server->hypervisor_id, 'server_id' => $this->server->id,
            'version' => 6, 'address' => '2001:db8:10::5', 'scope_key' => $pool->scopeKey()]);
        $other = IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $this->server->hypervisor_id, 'server_id' => Server::factory()->create()->id,
            'version' => 6, 'address' => '2001:db8:10::6', 'scope_key' => $pool->scopeKey()]);

        $this->actingAs($this->owner);
        $this->put(route('panel.servers.ipv6', [$this->server, $v6]), ['address' => '2001:db8:10::6'])->assertSessionHasErrors('guest');
        $this->put(route('panel.servers.ipv6', [$this->server, $v6]), ['address' => '2001:db8:99::1'])->assertSessionHasErrors('guest');
        $this->put(route('panel.servers.ipv6', [$this->server, $v6]), ['address' => '2001:db8:10::1'])->assertSessionHasErrors('guest');
        $this->put(route('panel.servers.ipv6', [$this->server, $other]), ['address' => '2001:db8:10::7'])->assertNotFound();
        $this->put(route('panel.servers.ipv6', [$this->server, $v6]), ['address' => '2001:DB8:10:0::cafe'])->assertSessionHasNoErrors();

        $this->assertSame(['2001:db8:10::cafe'], $this->server->ipAddresses()->where('version', 6)->pluck('address')->all());
        $this->assertNull($v6->fresh()->server_id, 'Stary adres wraca do puli');
        $this->assertSame(1, ServerJob::where('action', 'network')->count());
    }

    public function test_swap_z_kreatora(): void
    {
        $node = Hypervisor::factory()->create(['virtualization' => 'kvm', 'cpu_cores_total' => 64, 'ram_mb_total' => 262144, 'disk_gb_total' => 4000]);
        $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
        IpAddress::factory()->count(2)->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id]);
        $package = VpsPackage::factory()->create(['ip_count' => 1]);
        $linux = OsTemplate::factory()->create(['family' => 'debian']);
        $windows = OsTemplate::factory()->create(['family' => 'windows']);

        $server = app(ServerProvisioner::class)->order($this->owner, $package, $linux, 'swap.example.com', preferred: $node, swapMb: 2048);
        $this->assertSame(2048, ServerPayload::forCreate($server->fresh())['swap_mb']);
        $win = app(ServerProvisioner::class)->order($this->owner, $package, $windows, 'win.example.com', preferred: $node, swapMb: 2048);
        $this->assertNull($win->fresh()->swap_mb);
        $this->assertArrayNotHasKey('swap_mb', ServerPayload::forCreate($win->fresh()));

        $this->actingAs($this->owner)->get(route('panel.servers.create'))->assertOk()->assertSee('Plik wymiany (swap)');
    }
}
