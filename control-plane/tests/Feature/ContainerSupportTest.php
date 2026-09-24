<?php

namespace Tests\Feature;

use App\Domain\Provisioning\HypervisorEnrollment;
use App\Domain\Provisioning\TemplateDistributor;
use App\Enums\ServerState;
use App\Enums\Virtualization;
use App\Jobs\PrefetchTemplateJob;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\TemplateDownload;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Węzły bez sprzętowej wirtualizacji uruchamiają kontenery LXC. Najważniejsza
 * granica: kontener nie może trafić na węzeł KVM ani maszyna wirtualna na
 * węzeł kontenerów — w obu przypadkach provisioning wywaliłby się na węźle.
 */
class ContainerSupportTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        VpsPackage::factory()->create(['slug' => 'standard', 'disk_gb' => 20]);
    }

    private function nodeWithAddresses(Hypervisor $node): Hypervisor
    {
        $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
        IpAddress::factory()->count(2)->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id]);

        return $node;
    }

    private function order(OsTemplate $template)
    {
        return $this->actingAs($this->customer)->postJson('/api/v1/servers', [
            'package' => 'standard',
            'template' => $template->id,
            'hostname' => 'ct'.random_int(100, 999).'.example.com',
        ]);
    }

    // --- dobór węzła --------------------------------------------------------

    public function test_kontener_trafia_na_wezel_kontenerow(): void
    {
        Queue::fake();
        $kvm = $this->nodeWithAddresses(Hypervisor::factory()->create(['name' => 'kvm1']));
        $lxc = $this->nodeWithAddresses(Hypervisor::factory()->containers()->create(['name' => 'lxc1']));

        $this->order(OsTemplate::factory()->container()->create())->assertCreated();

        $server = Server::firstOrFail();
        $this->assertSame($lxc->id, $server->hypervisor_id);
        $this->assertSame(Virtualization::Lxc, $server->virtualization);
        $this->assertSame(0, $kvm->fresh()->cpu_cores_used, 'węzeł KVM nie może dostać rezerwacji');
    }

    public function test_maszyna_kvm_nie_trafia_na_wezel_kontenerow(): void
    {
        Queue::fake();
        $this->nodeWithAddresses(Hypervisor::factory()->containers()->create());

        $this->order(OsTemplate::factory()->create())
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Brak węzła typu KVM'));

        $this->assertSame(0, Server::count());
    }

    public function test_przebudowa_na_szablon_innego_typu_jest_odrzucana(): void
    {
        $server = Server::factory()->create([
            'user_id' => $this->customer->id,
            'virtualization' => 'lxc',
            'hypervisor_id' => Hypervisor::factory()->containers()->create()->id,
        ]);

        $this->actingAs($this->customer)
            ->postJson("/api/v1/servers/{$server->id}/rebuild", [
                'template' => OsTemplate::factory()->create()->id, // KVM
                'confirm' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'tego samego typu'));

        $this->assertSame(ServerState::Running, $server->fresh()->state);
    }

    // --- formularz zamówienia -----------------------------------------------

    public function test_formularz_pokazuje_tylko_systemy_z_dzialajacym_wezlem(): void
    {
        Hypervisor::factory()->create(); // tylko KVM
        OsTemplate::factory()->create(['name' => 'Ubuntu KVM']);
        OsTemplate::factory()->container()->create(['name' => 'Debian kontener']);

        $this->actingAs($this->customer)
            ->get(route('panel.servers.create'))
            ->assertOk()
            ->assertSee('Ubuntu')      // system (grupa)
            ->assertSee('KVM')         // wersja w grupie
            ->assertDontSee('Debian');
    }

    public function test_formularz_grupuje_maszyny_i_kontenery(): void
    {
        Hypervisor::factory()->create();
        Hypervisor::factory()->containers()->create();
        OsTemplate::factory()->create(['name' => 'Ubuntu KVM']);
        OsTemplate::factory()->container()->create(['name' => 'Debian kontener']);

        $this->actingAs($this->customer)
            ->get(route('panel.servers.create'))
            ->assertOk()
            ->assertSee('<strong>KVM:</strong>', false)
            ->assertSee('<strong>LXC:</strong>', false)
            ->assertSee('Debian')
            ->assertSee('kontener');
    }

    // --- rejestracja węzła --------------------------------------------------

    public function test_wezel_bez_vtx_rejestruje_sie_jako_kontenerowy(): void
    {
        $node = Hypervisor::factory()->create(['enrolled_at' => null, 'agent_url' => null]);
        $token = app(HypervisorEnrollment::class)->issueToken($node);

        $this->postJson("/enroll/{$token}/complete", [
            'hostname' => 'fastkvm',
            'cpu_cores' => 4,
            'ram_mb' => 8192,
            'disk_gb' => 100,
            'tls_cert' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
            'virtualization' => 'lxc',
        ])->assertOk();

        $this->assertSame(Virtualization::Lxc, $node->fresh()->virtualization);
    }

    public function test_starszy_instalator_bez_pola_rejestruje_kvm(): void
    {
        $node = Hypervisor::factory()->containers()->create(['enrolled_at' => null]);
        $token = app(HypervisorEnrollment::class)->issueToken($node);

        $this->postJson("/enroll/{$token}/complete", [
            'hostname' => 'node', 'cpu_cores' => 8, 'ram_mb' => 16384, 'disk_gb' => 200,
            'tls_cert' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
        ])->assertOk();

        $this->assertSame(Virtualization::Kvm, $node->fresh()->virtualization);
    }

    public function test_nieznany_rodzaj_wezla_jest_odrzucany(): void
    {
        $node = Hypervisor::factory()->create(['enrolled_at' => null]);
        $token = app(HypervisorEnrollment::class)->issueToken($node);

        $this->postJson("/enroll/{$token}/complete", [
            'hostname' => 'node', 'cpu_cores' => 8, 'ram_mb' => 16384, 'disk_gb' => 200,
            'tls_cert' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
            'virtualization' => 'openvz',
        ])->assertStatus(422);
    }

    // --- szablony w panelu administratora -----------------------------------

    public function test_dodanie_szablonu_kontenera_rozsyla_go_na_wezly(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Hypervisor::factory()->containers()->count(2)->create();
        Hypervisor::factory()->create(['enrolled_at' => now()]); // KVM — pomijany

        $this->actingAs($admin)->post(route('panel.admin.templates.store'), [
            'virtualization' => 'lxc',
            'name' => 'Debian 12',
            'family' => 'debian',
            'version' => '12',
            'image_file' => 'debian/12/cloud',
            'min_disk_gb' => 4,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, TemplateDownload::count());
        Queue::assertPushed(PrefetchTemplateJob::class, 2);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badAliases(): array
    {
        return [
            'zdalny serwer obrazów' => ['lxc', 'evil:debian/12'],
            'ścieżka w górę' => ['lxc', '../../etc/passwd'],
            'ukośnik w pliku KVM' => ['kvm', 'debian/12/cloud'],
            'wielkie litery w aliasie' => ['lxc', 'Debian/12'],
        ];
    }

    #[DataProvider('badAliases')]
    public function test_niebezpieczne_obrazy_sa_odrzucane(string $type, string $image): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('panel.admin.templates.store'), [
            'virtualization' => $type, 'name' => 'X', 'family' => 'debian',
            'version' => '12', 'image_file' => $image, 'min_disk_gb' => 4,
        ])->assertSessionHasErrors('image_file');

        $this->assertSame(0, OsTemplate::count());
    }

    public function test_windows_nie_moze_byc_kontenerem(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('panel.admin.templates.store'), [
            'virtualization' => 'lxc', 'name' => 'Windows', 'family' => 'windows',
            'version' => '2022', 'image_file' => 'windows/2022', 'min_disk_gb' => 40,
        ])->assertSessionHasErrors('family');
    }

    public function test_szablon_z_katalogu_jednym_kliknieciem_i_bez_duplikatow(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Hypervisor::factory()->containers()->create();

        $this->actingAs($admin)->post(route('panel.admin.templates.catalog', 'debian-12'))
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('panel.admin.templates.catalog', 'debian-12'));

        $this->assertSame(1, OsTemplate::where('image_file', 'debian/12/cloud')->count());
        $template = OsTemplate::firstOrFail();
        $this->assertTrue($template->isContainer());
        $this->assertSame(1, TemplateDownload::count());
    }

    public function test_nieznany_wpis_katalogu_to_404(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('panel.admin.templates.catalog', 'nie-ma-takiego'))
            ->assertNotFound();
    }

    public function test_strona_szablonow_pokazuje_stan_na_wezlach(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $node = Hypervisor::factory()->containers()->create(['name' => 'lxc-waw']);
        $template = OsTemplate::factory()->container()->create();
        TemplateDownload::create([
            'os_template_id' => $template->id, 'hypervisor_id' => $node->id,
            'status' => 'failed', 'error' => 'Image not found',
        ]);

        $this->actingAs($admin)->get(route('panel.admin.templates'))
            ->assertOk()
            ->assertSee('lxc-waw: błąd')
            ->assertSee('Image not found')
            ->assertSee('Ponów pobieranie');
    }

    // --- dystrybucja --------------------------------------------------------

    public function test_dystrybucja_pomija_gotowe_i_trwajace_pobrania(): void
    {
        Queue::fake();
        $node = Hypervisor::factory()->containers()->create();
        $ready = OsTemplate::factory()->container('debian/12/cloud')->create();
        $busy = OsTemplate::factory()->container('ubuntu/24.04/cloud')->create();
        $missing = OsTemplate::factory()->container('almalinux/9/cloud')->create();

        TemplateDownload::create(['os_template_id' => $ready->id, 'hypervisor_id' => $node->id, 'status' => 'ready']);
        TemplateDownload::create(['os_template_id' => $busy->id, 'hypervisor_id' => $node->id, 'status' => 'downloading']);

        $queued = app(TemplateDistributor::class)->syncNode($node);

        $this->assertSame(1, $queued);
        $this->assertSame('queued', TemplateDownload::where('os_template_id', $missing->id)->value('status'));
    }

    public function test_zawieszone_pobranie_jest_zlecane_ponownie(): void
    {
        Queue::fake();
        $node = Hypervisor::factory()->containers()->create();
        $template = OsTemplate::factory()->container()->create();
        $stuck = TemplateDownload::create([
            'os_template_id' => $template->id, 'hypervisor_id' => $node->id, 'status' => 'downloading',
        ]);
        // Padł worker, zlecenie zawisło.
        TemplateDownload::whereKey($stuck->id)->update(['updated_at' => now()->subHours(3)]);

        $this->assertSame(1, app(TemplateDistributor::class)->syncNode($node));
    }

    public function test_bledy_nie_sa_ponawiane_automatycznie_tylko_na_zadanie(): void
    {
        Queue::fake();
        $node = Hypervisor::factory()->containers()->create();
        $template = OsTemplate::factory()->container()->create();
        TemplateDownload::create([
            'os_template_id' => $template->id, 'hypervisor_id' => $node->id, 'status' => 'failed',
        ]);

        $distributor = app(TemplateDistributor::class);
        $this->assertSame(0, $distributor->syncNode($node), 'heartbeat nie może spamować ponowieniami');
        $this->assertSame(1, $distributor->retryFailed($template));
    }

    public function test_wezel_kvm_nie_dostaje_szablonow_kontenerow(): void
    {
        Queue::fake();
        OsTemplate::factory()->container()->create();

        $this->assertSame(0, app(TemplateDistributor::class)->syncNode(
            Hypervisor::factory()->create(['enrolled_at' => now()])
        ));
    }

    // --- pobieranie na węźle ------------------------------------------------

    private function download(): TemplateDownload
    {
        return TemplateDownload::create([
            'os_template_id' => OsTemplate::factory()->container()->create()->id,
            'hypervisor_id' => Hypervisor::factory()->containers()->create()->id,
            'status' => 'queued',
        ]);
    }

    public function test_zadanie_zleca_pobranie_i_czeka_na_wynik(): void
    {
        $download = $this->download();
        Http::fake(['*/images/prefetch' => Http::response(['job_id' => 'agent-1', 'status' => 'queued'], 202)]);

        (new PrefetchTemplateJob($download->id))->handle();

        $download->refresh();
        $this->assertSame('downloading', $download->status);
        $this->assertSame('agent-1', $download->agent_job_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/images/prefetch')
            && $r->data()['alias'] === 'debian/12/cloud'
            && $r->hasHeader('X-VH-Signature'));
    }

    public function test_zakonczone_pobranie_oznacza_szablon_jako_gotowy(): void
    {
        $download = $this->download();
        $download->update(['status' => 'downloading', 'agent_job_id' => 'agent-1']);
        Http::fake(['*/jobs/agent-1' => Http::response(['job_id' => 'agent-1', 'status' => 'done'])]);

        (new PrefetchTemplateJob($download->id))->handle();

        $this->assertSame('ready', $download->fresh()->status);
        $this->assertNotNull($download->fresh()->finished_at);
    }

    public function test_nieudane_pobranie_zapisuje_przyczyne(): void
    {
        $download = $this->download();
        $download->update(['status' => 'downloading', 'agent_job_id' => 'agent-1']);
        Http::fake(['*/jobs/agent-1' => Http::response([
            'job_id' => 'agent-1', 'status' => 'failed', 'error' => 'Image not found on remote',
        ])]);

        (new PrefetchTemplateJob($download->id))->handle();

        $this->assertSame('failed', $download->fresh()->status);
        $this->assertSame('Image not found on remote', $download->fresh()->error);
    }

    public function test_trwajace_pobranie_pozostaje_w_toku(): void
    {
        $download = $this->download();
        $download->update(['status' => 'downloading', 'agent_job_id' => 'agent-1']);
        Http::fake(['*/jobs/agent-1' => Http::response(['job_id' => 'agent-1', 'status' => 'running'])]);

        (new PrefetchTemplateJob($download->id))->handle();

        $this->assertSame('downloading', $download->fresh()->status);
    }

    public function test_odmowa_wezla_konczy_pobieranie_bledem(): void
    {
        $download = $this->download();
        Http::fake(['*/images/prefetch' => Http::response(['detail' => 'Ten węzeł uruchamia maszyny KVM'], 409)]);

        (new PrefetchTemplateJob($download->id))->handle();

        $this->assertSame('failed', $download->fresh()->status);
        $this->assertStringContainsString('KVM', $download->fresh()->error);
    }

    // --- heartbeat ----------------------------------------------------------

    public function test_heartbeat_wezla_kontenerow_dociaga_szablony(): void
    {
        Queue::fake();
        Hypervisor::factory()->containers()->create();
        OsTemplate::factory()->container()->create();
        Http::fake(['*/health' => Http::response([
            'driver' => 'lxc', 'virtualization' => 'lxc', 'running_vms' => 0,
            'ram_mb_free' => 1000, 'disk_gb_free' => 100,
        ])]);

        $this->artisan('virthub:poll-hypervisors')->assertSuccessful();

        Queue::assertPushed(PrefetchTemplateJob::class, 1);
    }

    public function test_api_katalogu_podaje_rodzaj_szablonu(): void
    {
        OsTemplate::factory()->container()->create();

        $this->actingAs($this->customer)->getJson('/api/v1/os-templates')
            ->assertOk()
            ->assertJsonPath('data.0.virtualization', 'lxc');
    }
}
