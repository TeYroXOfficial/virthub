<?php

namespace Tests\Feature;

use App\Models\Hypervisor;
use App\Models\IsoDownload;
use App\Models\IsoImage;
use App\Models\OsTemplate;
use App\Models\TemplateDownload;
use App\Models\User;
use App\Support\OsIcon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DownloadProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_pasek_postepu_szablonu_z_wezla(): void
    {
        $node = Hypervisor::factory()->containers()->create(['name' => 'lxc-1']);
        $template = OsTemplate::factory()->container()->create();
        $download = TemplateDownload::create([
            'os_template_id' => $template->id, 'hypervisor_id' => $node->id,
            'status' => 'downloading', 'agent_job_id' => 'a-1',
        ]);

        $this->actingAs($this->admin)->get(route('panel.admin.templates'))
            ->assertOk()->assertSee('data-download="template-'.$download->id.'"', false);

        Http::fake(['*/jobs/a-1' => Http::sequence()
            ->push(['job_id' => 'a-1', 'status' => 'running', 'stage' => 'download', 'progress' => 42, 'detail' => '42% · 12.3MB/s'])
            ->push(['job_id' => 'a-1', 'status' => 'done', 'stage' => 'download', 'progress' => 100])]);

        $this->actingAs($this->admin)->getJson(route('panel.admin.downloads.status'))
            ->assertOk()
            ->assertJsonPath('data.0.progress', 42)
            ->assertJsonPath('data.0.detail', '42% · 12.3MB/s')
            ->assertJsonPath('data.0.finished', false);

        \Illuminate\Support\Facades\Cache::flush();

        $this->actingAs($this->admin)->getJson(route('panel.admin.downloads.status'))
            ->assertJsonPath('data.0.finished', true);
        $this->assertSame('ready', $download->fresh()->status);
        $this->assertSame(100, $download->fresh()->progress);
    }

    public function test_pobieranie_iso_konczy_sie_z_rozmiarem(): void
    {
        $node = Hypervisor::factory()->create();
        $iso = IsoImage::factory()->create();
        $download = IsoDownload::create([
            'iso_image_id' => $iso->id, 'hypervisor_id' => $node->id,
            'status' => 'downloading', 'agent_job_id' => 'i-1',
        ]);

        Http::fake(['*/jobs/i-1' => Http::response([
            'job_id' => 'i-1', 'status' => 'done', 'result' => ['size_bytes' => 734003200],
        ])]);

        $this->actingAs($this->admin)->getJson(route('panel.admin.downloads.status'))
            ->assertJsonPath('data.0.kind', 'iso')
            ->assertJsonPath('data.0.finished', true);
        $this->assertSame('ready', $download->fresh()->status);
        $this->assertSame(734003200, (int) $iso->fresh()->size_bytes);
    }

    public function test_klient_nie_widzi_statusu_pobieran(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->getJson(route('panel.admin.downloads.status'))->assertForbidden();
    }

    public function test_ikony_systemow(): void
    {
        $this->assertStringContainsString('<path d="', OsIcon::path('ubuntu'));
        $this->assertStringContainsString('<path d="', OsIcon::path('windows'));
        $this->assertNull(OsIcon::path('nieznany'));
        $this->assertNull(OsIcon::path('../../.env'), 'nazwa rodziny nie może wyjść poza katalog ikon');
        $this->assertSame('#E95420', OsIcon::colors('ubuntu')[0]);

        Hypervisor::factory()->create();
        OsTemplate::factory()->create();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->get(route('panel.servers.create'))
            ->assertSee('<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path', false);
    }
}
