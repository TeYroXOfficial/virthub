<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Jobs\PrefetchTemplateJob;
use App\Models\Hypervisor;
use App\Models\OsTemplate;
use App\Models\TemplateDownload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Windows Server budowany Packerem na węzłach KVM i maszyny z niego. */
class WindowsBuildTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_budowa_z_iso_spla_na_kazdym_wezle_kvm(): void
    {
        Queue::fake();
        Hypervisor::factory()->count(2)->create(['enrolled_at' => now()]);
        Hypervisor::factory()->containers()->create();

        $this->actingAs($this->admin)->get(route('panel.admin.templates'))->assertOk()->assertSee('Windows Server 2022 Standard');
        $this->actingAs($this->admin)->post(route('panel.admin.templates.build', 'windows-2022'), [])
            ->assertSessionHasErrors('iso_url');
        $this->actingAs($this->admin)->post(route('panel.admin.templates.build', 'windows-2022'), [
            'iso_url' => 'https://iso.example.com/SW_DVD9_Win_Server_2022.iso', 'iso_sha256' => str_repeat('AB', 32),
        ])->assertSessionHasNoErrors();

        $template = OsTemplate::query()->where('image_file', 'windows-server-2022.qcow2')->sole();
        $this->assertSame('windows', $template->family);
        $this->assertTrue($template->cloud_init_support && $template->isBuilt() && $template->isDistributed());
        $this->assertSame(2, TemplateDownload::query()->count());
        Queue::assertPushed(PrefetchTemplateJob::class, 2);

        $request = $template->buildRequest();
        $this->assertSame([
            'name' => 'windows-server-2022.qcow2', 'edition' => '2022', 'evaluation' => false,
            'iso_url' => 'https://iso.example.com/SW_DVD9_Win_Server_2022.iso', 'iso_sha256' => str_repeat('ab', 32),
            'disk_gb' => 20, 'files_url' => config('virthub.template_build_files'),
        ], $request);
    }

    public function test_wersja_ewaluacyjna_2025_z_kluczem_kms(): void
    {
        Queue::fake();
        $this->actingAs($this->admin)->post(route('panel.admin.templates.build', 'windows-2025'), ['evaluation' => '1'])
            ->assertSessionHasNoErrors();

        $request = OsTemplate::query()->where('build_recipe', 'windows-2025')->sole()->buildRequest();
        $this->assertTrue($request['evaluation']);
        $this->assertStringContainsString('SERVER_EVAL', $request['iso_url']);
        $this->assertSame('TVRH6-WHNXV-R9WG3-9XRFY-MY832', $request['kms_key']);
        $this->actingAs($this->admin)->post(route('panel.admin.templates.build', 'windows-2030'))->assertNotFound();
    }

    public function test_zadanie_zleca_budowe_agentowi(): void
    {
        Http::fake(['*/templates/build' => Http::response(['job_id' => 'agent-build-1'], 202)]);
        $template = OsTemplate::factory()->create(['image_file' => 'windows-server-2019.qcow2', 'family' => 'windows',
            'build_recipe' => 'windows-2019', 'build_options' => ['evaluation' => true]]);
        $download = TemplateDownload::create(['os_template_id' => $template->id, 'hypervisor_id' => Hypervisor::factory()->create()->id, 'status' => 'queued']);

        $job = new PrefetchTemplateJob($download->id);
        $this->assertTrue($job->retryUntil()->greaterThan(now()->addHours(10)), 'budowa ma czas na kilka godzin');
        $job->handle();

        $this->assertSame('agent-build-1', $download->fresh()->agent_job_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/templates/build') && $r->data()['edition'] === '2019' && $r->data()['evaluation'] === true);
    }

    public function test_maszyna_windows_dostaje_os_type_i_konto_administratora(): void
    {
        $windows = OsTemplate::factory()->create(['family' => 'windows', 'image_file' => 'windows-server-2022.qcow2']);
        $linux = OsTemplate::factory()->create();

        $this->assertSame('windows', ServerPayload::osType($windows));
        $this->assertSame('linux', ServerPayload::osType($linux));
        $this->assertSame('linux', ServerPayload::osType(null));
    }

    public function test_klient_widzi_windows_dopiero_po_zbudowaniu(): void
    {
        $template = OsTemplate::factory()->create(['family' => 'windows', 'image_file' => 'windows-server-2022.qcow2', 'build_recipe' => 'windows-2022']);
        $ids = fn () => app(\App\Domain\Provisioning\TemplateCatalog::class)->choices(['kvm'])->flatMap(fn ($c) => $c['templates']->pluck('id'))->all();
        $this->assertNotContains($template->id, $ids());

        TemplateDownload::create(['os_template_id' => $template->id, 'hypervisor_id' => Hypervisor::factory()->create()->id, 'status' => 'ready']);
        $this->assertContains($template->id, $ids());
    }
}
