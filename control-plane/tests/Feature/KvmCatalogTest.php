<?php

namespace Tests\Feature;

use App\Domain\Provisioning\CloudImageChecksum;
use App\Domain\Provisioning\TemplateCatalog;
use App\Domain\Provisioning\TemplateDistributor;
use App\Jobs\PrefetchTemplateJob;
use App\Models\Hypervisor;
use App\Models\OsTemplate;
use App\Models\TemplateDownload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Katalog KVM: oficjalne obrazy cloud rozsyłane na węzły jednym kliknięciem. */
class KvmCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_dodanie_z_katalogu_rozsyla_na_wezly_kvm(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $kvm = Hypervisor::factory()->count(2)->create(['enrolled_at' => now()]);
        Hypervisor::factory()->containers()->create();

        $this->actingAs($admin)->get(route('panel.admin.templates'))->assertOk()->assertSee('Rocky Linux 9');
        $this->actingAs($admin)->post(route('panel.admin.templates.kvm-catalog', 'debian-13'))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('panel.admin.templates.kvm-catalog', 'debian-13'));

        $template = OsTemplate::query()->where('image_file', 'debian-13.qcow2')->sole();
        $this->assertFalse($template->isContainer());
        $this->assertTrue($template->isDistributed());
        $this->assertStringContainsString('cloud.debian.org', $template->source_url);
        $this->assertSame($kvm->pluck('id')->sort()->values()->all(), TemplateDownload::query()->pluck('hypervisor_id')->sort()->values()->all());
        Queue::assertPushed(PrefetchTemplateJob::class, 2);

        // Nowy węzeł KVM dostaje obraz przy synchronizacji.
        $late = Hypervisor::factory()->create(['enrolled_at' => now()]);
        $this->assertSame(1, app(TemplateDistributor::class)->syncNode($late));

        $this->actingAs($admin)->post(route('panel.admin.templates.kvm-catalog', 'nie-ma'))->assertNotFound();
    }

    public function test_istniejacy_szablon_dostaje_zrodlo_zamiast_duplikatu(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $existing = OsTemplate::factory()->create(['image_file' => 'ubuntu-24.04.qcow2', 'is_active' => false]);

        $this->actingAs($admin)->post(route('panel.admin.templates.kvm-catalog', 'ubuntu-2404'));

        $this->assertSame(1, OsTemplate::query()->where('image_file', 'ubuntu-24.04.qcow2')->count());
        $existing->refresh();
        $this->assertTrue($existing->is_active);
        $this->assertNotNull($existing->source_url);
    }

    public function test_zadanie_wysyla_adres_i_sume_z_pliku_dystrybucji(): void
    {
        $sha = str_repeat('ab', 64);
        Http::fake([
            'cloud.debian.org/*' => Http::response("{$sha}  debian-13-genericcloud-amd64.qcow2\n".str_repeat('cd', 64)."  debian-13-genericcloud-amd64.raw\n"),
            '*/templates/download' => Http::response(['job_id' => 'agent-7'], 202),
        ]);
        $entry = config('virthub.kvm_catalog.debian-13');
        $template = OsTemplate::factory()->create(['image_file' => 'debian-13.qcow2', 'source_url' => $entry['url'], 'checksum_url' => $entry['checksum_url']]);
        $download = TemplateDownload::create(['os_template_id' => $template->id, 'hypervisor_id' => Hypervisor::factory()->create()->id, 'status' => 'queued']);

        (new PrefetchTemplateJob($download->id))->handle();

        $this->assertSame('agent-7', $download->fresh()->agent_job_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/templates/download')
            && $r->data() === ['name' => 'debian-13.qcow2', 'url' => $entry['url'], 'sha512' => $sha]);
    }

    public function test_brak_obrazu_w_sumach_konczy_pobranie_bledem(): void
    {
        Http::fake(['cloud.debian.org/*' => Http::response("x  inny.qcow2\n")]);
        $entry = config('virthub.kvm_catalog.debian-13');
        $template = OsTemplate::factory()->create(['image_file' => 'debian-13.qcow2', 'source_url' => $entry['url'], 'checksum_url' => $entry['checksum_url']]);
        $download = TemplateDownload::create(['os_template_id' => $template->id, 'hypervisor_id' => Hypervisor::factory()->create()->id, 'status' => 'queued']);

        (new PrefetchTemplateJob($download->id))->handle();

        $this->assertSame('failed', $download->fresh()->status);
        $this->assertStringContainsString('debian-13-genericcloud-amd64.qcow2', $download->fresh()->error);
    }

    public function test_formaty_plikow_sum(): void
    {
        $a = str_repeat('a', 64);
        $this->assertSame($a, CloudImageChecksum::find("{$a} *noble-server-cloudimg-amd64.img\n", 'noble-server-cloudimg-amd64.img'));
        $this->assertSame($a, CloudImageChecksum::find("# x: 1 bytes\nSHA256 (Rocky-9.qcow2) = {$a}\n", 'Rocky-9.qcow2'));
        $this->assertNull(CloudImageChecksum::find("{$a}  Rocky-9.qcow2.bak\n", 'Rocky-9.qcow2'));
    }

    public function test_klient_widzi_obraz_z_katalogu_dopiero_gdy_jest_na_wezle(): void
    {
        $template = OsTemplate::factory()->create(['image_file' => 'rocky-9.qcow2', 'source_url' => 'https://x/y.qcow2']);
        $manual = OsTemplate::factory()->create(['image_file' => 'own.qcow2']);
        $ids = fn () => app(TemplateCatalog::class)->choices(['kvm'])->flatMap(fn ($c) => $c['templates']->pluck('id'))->all();

        $this->assertNotContains($template->id, $ids());
        $this->assertContains($manual->id, $ids());

        TemplateDownload::create(['os_template_id' => $template->id, 'hypervisor_id' => Hypervisor::factory()->create()->id, 'status' => 'ready']);
        $this->assertContains($template->id, $ids());
    }
}
