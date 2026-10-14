<?php

namespace Tests\Feature;

use App\Domain\Provisioning\IsoLibrary;
use App\Domain\Provisioning\ServerCleanup;
use App\Jobs\DownloadIsoJob;
use App\Models\Hypervisor;
use App\Models\IsoDownload;
use App\Models\IsoImage;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomIsoTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->server = Server::factory()->create(['hypervisor_id' => Hypervisor::factory()->create(['enrolled_at' => now()])->id]);
    }

    public function test_klient_dodaje_wlasny_obraz_tylko_na_swoj_wezel(): void
    {
        Hypervisor::factory()->create(['enrolled_at' => now()]); // inny węzeł KVM — nie pobiera

        $this->actingAs($this->server->user)->post(route('panel.servers.iso.custom', $this->server), [
            'custom_name' => 'Alpine', 'custom_url' => 'https://dl-cdn.alpinelinux.org/alpine.iso',
        ])->assertRedirect();

        $iso = IsoImage::firstOrFail();
        $this->assertSame($this->server->id, $iso->server_id);
        $this->assertFalse($iso->is_public);
        $this->assertSame([$this->server->hypervisor_id], IsoDownload::pluck('hypervisor_id')->all());
        Queue::assertPushed(DownloadIsoJob::class);

        // Limit 1 na maszynę.
        $this->post(route('panel.servers.iso.custom', $this->server), ['custom_name' => 'Drugi', 'custom_url' => 'https://example.com/b.iso'])
            ->assertSessionHasErrors('custom_url');
    }

    public function test_agent_dostaje_flage_untrusted_i_limit(): void
    {
        Setting::put(['iso.custom_max_gb' => '4']);
        $iso = app(IsoLibrary::class)->addCustom($this->server, 'X', 'https://example.com/x.iso');
        Http::fake(['*/images/iso' => Http::response(['job_id' => 'agent-1', 'status' => 'queued'], 202)]);

        (new DownloadIsoJob($iso->downloads()->first()->id))->handle();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/images/iso') && $r['untrusted'] === true && $r['max_gb'] === 4);
    }

    public function test_obraz_biblioteki_nie_ma_flagi_untrusted(): void
    {
        $iso = app(IsoLibrary::class)->add(['name' => 'Debian', 'url' => 'https://example.com/d.iso']);
        Http::fake(['*/images/iso' => Http::response(['job_id' => 'agent-1', 'status' => 'queued'], 202)]);
        (new DownloadIsoJob($iso->downloads()->first()->id))->handle();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/images/iso') && ! isset($r['untrusted']));
    }

    public function test_cudza_maszyna_nie_widzi_ani_nie_zamontuje_obrazu(): void
    {
        $iso = app(IsoLibrary::class)->addCustom($this->server, 'ObrazKlientaXYZ', 'https://example.com/x.iso');
        IsoDownload::query()->update(['status' => IsoDownload::STATUS_READY]);
        $other = Server::factory()->create(['hypervisor_id' => $this->server->hypervisor_id]);

        $library = app(IsoLibrary::class);
        $this->assertSame([$iso->id], $library->availableFor($this->server->hypervisor_id, false, $this->server)->pluck('id')->all());
        $this->assertSame([], $library->availableFor($other->hypervisor_id, false, $other)->pluck('id')->all());
        $this->assertSame([], $library->availableFor($other->hypervisor_id, true, $other)->pluck('id')->all());

        $this->actingAs($other->user)->post(route('panel.servers.iso', $other), ['iso' => $iso->id])->assertForbidden();
        $this->actingAs($other->user)->delete(route('panel.servers.iso.custom.destroy', [$other, $iso]))->assertNotFound();

        // Własny obraz nie jest w bibliotece administratora.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get(route('panel.admin.isos'))->assertOk()->assertDontSee('ObrazKlientaXYZ');
    }

    public function test_wylaczone_przez_administratora(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->put(route('panel.admin.isos.custom-settings'), ['custom_limit' => 0, 'custom_max_gb' => 5])->assertRedirect();

        $this->actingAs($this->server->user)->post(route('panel.servers.iso.custom', $this->server), [
            'custom_name' => 'X', 'custom_url' => 'https://example.com/x.iso',
        ])->assertSessionHasErrors('custom_url');
        $this->assertSame(0, IsoImage::count());
    }

    public function test_usuniecie_maszyny_sprzata_obraz(): void
    {
        app(IsoLibrary::class)->addCustom($this->server, 'X', 'https://example.com/x.iso');
        Http::fake(['*' => Http::response(['deleted' => true])]);

        app(ServerCleanup::class)->finalise($this->server);

        $this->assertSame(0, IsoImage::count());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/images/iso/vm'.$this->server->id.'-'));
    }

    public function test_strona_maszyny_pokazuje_formularz(): void
    {
        $this->actingAs($this->server->user)->get(route('panel.servers.show', $this->server))->assertOk()->assertSee('Własny obraz ISO');
    }
}
