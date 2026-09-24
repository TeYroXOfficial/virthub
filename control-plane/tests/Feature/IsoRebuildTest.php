<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\IsoLibrary;
use App\Enums\ServerState;
use App\Jobs\DownloadIsoJob;
use App\Jobs\RunServerActionJob;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\IsoDownload;
use App\Models\IsoImage;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IsoRebuildTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Hypervisor $node;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->node = Hypervisor::factory()->create(['enrolled_at' => now()]);
        $this->server = Server::factory()->create([
            'user_id' => $this->owner->id,
            'hypervisor_id' => $this->node->id,
            'hostname' => 'vps1.example.com',
        ]);
    }

    private function readyIso(array $attributes = []): IsoImage
    {
        $iso = IsoImage::factory()->create($attributes);
        IsoDownload::create([
            'iso_image_id' => $iso->id,
            'hypervisor_id' => $this->node->id,
            'status' => IsoDownload::STATUS_READY,
        ]);

        return $iso;
    }

    // --- reinstalacja ---------------------------------------------------------------

    public function test_reinstalacja_wymaga_wpisania_nazwy_hosta(): void
    {
        $template = OsTemplate::factory()->create(['name' => 'Debian 13', 'image_file' => 'debian-13.qcow2']);

        $this->actingAs($this->owner)->post(route('panel.servers.rebuild', $this->server), [
            'template' => $template->id,
            'confirm_hostname' => 'inny.example.com',
        ])->assertSessionHasErrors('confirm_hostname');

        $this->assertDatabaseCount('server_jobs', 0);

        $this->actingAs($this->owner)->post(route('panel.servers.rebuild', $this->server), [
            'template' => $template->id,
            'confirm_hostname' => 'VPS1.example.com',
        ])->assertSessionHasNoErrors();

        $server = $this->server->fresh();
        $this->assertSame(ServerState::Rebuilding, $server->state);
        $this->assertSame($template->id, $server->os_template_id);
        $job = ServerJob::where('action', 'rebuild')->sole();
        $this->assertSame('debian-13.qcow2', $job->payload['template']);
        Queue::assertPushed(RunServerActionJob::class);
    }

    public function test_reinstalacja_odrzuca_szablon_innej_wirtualizacji(): void
    {
        $container = OsTemplate::factory()->container()->create();

        $this->actingAs($this->owner)->post(route('panel.servers.rebuild', $this->server), [
            'template' => $container->id,
            'confirm_hostname' => 'vps1.example.com',
        ])->assertSessionHasErrors('template');

        $this->assertSame(ServerState::Running, $this->server->fresh()->state);
    }

    public function test_reinstalacja_przekazuje_adresacje_do_agenta(): void
    {
        $pool = IpPool::factory()->create(['hypervisor_id' => $this->node->id]);
        IpAddress::factory()->create([
            'ip_pool_id' => $pool->id,
            'hypervisor_id' => $this->node->id,
            'server_id' => $this->server->id,
        ]);
        Http::fake(['*' => Http::response(['job_id' => 'agent-1', 'status' => 'queued'], 202)]);

        $job = app(\App\Domain\Provisioning\ServerProvisioner::class)
            ->rebuild($this->server, OsTemplate::factory()->create());
        (new RunServerActionJob($job->id))->handle();

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/rebuild')
                && count($request['interfaces'] ?? []) === 1
                && ! empty($request['interfaces'][0]['address'])
                && ! empty($request['nameservers']);
        });
    }

    public function test_support_nie_reinstaluje_cudzej_maszyny(): void
    {
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);

        $this->actingAs($support)->post(route('panel.servers.rebuild', $this->server), [
            'template' => OsTemplate::factory()->create()->id,
            'confirm_hostname' => 'vps1.example.com',
        ])->assertForbidden();
    }

    // --- ISO ----------------------------------------------------------------------

    public function test_dodanie_obrazu_pobiera_go_na_wezly_kvm(): void
    {
        Hypervisor::factory()->containers()->create();
        Hypervisor::factory()->create(); // niezarejestrowany — pomijany
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('panel.admin.isos.store'), [
            'name' => 'Debian 13 netinst',
            'url' => 'https://cdimage.debian.org/debian-13-amd64-netinst.iso',
            'sha256' => str_repeat('A', 64),
            'is_public' => 1,
        ])->assertSessionHasNoErrors();

        $iso = IsoImage::sole();
        $this->assertSame('debian-13-netinst.iso', $iso->filename);
        $this->assertSame(str_repeat('a', 64), $iso->sha256);
        $this->assertSame([$this->node->id], $iso->downloads()->pluck('hypervisor_id')->all());
        Queue::assertPushed(DownloadIsoJob::class, 1);

        $this->actingAs($admin)->get(route('panel.admin.isos'))->assertOk()->assertSee('Debian 13 netinst');
    }

    public function test_pobieranie_obrazu_konczy_sie_statusem_gotowy(): void
    {
        $iso = IsoImage::factory()->create();
        $download = IsoDownload::create([
            'iso_image_id' => $iso->id,
            'hypervisor_id' => $this->node->id,
            'status' => IsoDownload::STATUS_QUEUED,
        ]);

        Http::fake([
            '*/images/iso' => Http::response(['job_id' => 'agent-1', 'status' => 'queued'], 202),
            '*/jobs/agent-1' => Http::response(['job_id' => 'agent-1', 'status' => 'done', 'result' => ['size_bytes' => 123456]]),
        ]);

        (new DownloadIsoJob($download->id))->handle();
        (new DownloadIsoJob($download->id))->handle();

        $this->assertSame(IsoDownload::STATUS_READY, $download->fresh()->status);
        $this->assertTrue($iso->fresh()->isReadyOn($this->node->id));
    }

    public function test_klient_montuje_gotowy_obraz(): void
    {
        $iso = $this->readyIso();

        $this->actingAs($this->owner)->post(route('panel.servers.iso', $this->server), [
            'iso' => $iso->id, 'boot' => 1, 'restart' => 1,
        ])->assertSessionHasNoErrors();

        $job = ServerJob::where('action', 'iso')->sole();
        $this->assertSame($iso->filename, $job->payload['filename']);
        $this->assertTrue($job->payload['boot']);
        $this->assertTrue($job->payload['restart']);

        Http::fake(['*' => Http::response(['job_id' => 'agent-2', 'status' => 'queued'], 202)]);
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/vm/{$this->server->agent_uuid}/iso")
            && $r['iso'] === $iso->filename && $r['boot'] === true);

        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'done', 'result' => ['boot' => true, 'state' => 'running']]);
        $server = $this->server->fresh();
        $this->assertSame($iso->id, $server->iso_image_id);
        $this->assertTrue($server->boot_from_iso);

        $this->actingAs($this->owner)->get(route('panel.servers.show', $server))
            ->assertOk()->assertSee($iso->name)->assertSee('z płyty');
    }

    public function test_obraz_niepobrany_na_wezel_i_ukryty_sa_odrzucane(): void
    {
        $missing = IsoImage::factory()->create();
        $hidden = $this->readyIso(['is_public' => false]);

        $this->actingAs($this->owner)->post(route('panel.servers.iso', $this->server), ['iso' => $missing->id])
            ->assertSessionHasErrors('iso');
        $this->actingAs($this->owner)->post(route('panel.servers.iso', $this->server), ['iso' => $hidden->id])
            ->assertForbidden();

        $this->assertDatabaseCount('server_jobs', 0);
    }

    public function test_kontener_nie_ma_iso(): void
    {
        $container = Server::factory()->create([
            'user_id' => $this->owner->id,
            'virtualization' => 'lxc',
            'hypervisor_id' => Hypervisor::factory()->containers()->create()->id,
        ]);

        $this->actingAs($this->owner)->post(route('panel.servers.iso', $container), ['iso' => null])
            ->assertForbidden();
        $this->actingAs($this->owner)->get(route('panel.servers.show', $container))
            ->assertOk()->assertDontSee('id="iso"', false);
    }

    public function test_bez_uprawnienia_iso(): void
    {
        $this->owner->forceFill(['permissions' => ['servers.power']])->save();

        $this->actingAs($this->owner->fresh())->post(route('panel.servers.iso', $this->server), ['iso' => null])
            ->assertForbidden();
        $this->actingAs($this->owner->fresh())->get(route('panel.servers.show', $this->server))
            ->assertOk()->assertDontSee('id="iso"', false)->assertDontSee('id="reinstall"', false);
    }

    public function test_zamontowanego_obrazu_nie_mozna_usunac(): void
    {
        $iso = $this->readyIso();
        $this->server->forceFill(['iso_image_id' => $iso->id])->save();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(IsoLibrary::class)->delete($iso);
    }
}
