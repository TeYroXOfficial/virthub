<?php

namespace Tests\Feature;

use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    // --- dostęp -------------------------------------------------------------

    public function test_klient_nie_ma_dostepu_do_administracji(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        foreach ([
            'panel.admin.index',
            'panel.admin.hypervisors',
            'panel.admin.packages',
            'panel.admin.ip-pools',
            'panel.admin.servers',
        ] as $route) {
            $this->actingAs($customer)->get(route($route))->assertForbidden();
        }
    }

    public function test_wsparcie_nie_ma_dostepu_do_administracji(): void
    {
        // Support widzi maszyny przez API wsparcia, ale nie zmienia oferty
        // ani konfiguracji floty.
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);

        $this->actingAs($support)->get(route('panel.admin.hypervisors'))->assertForbidden();
    }

    public function test_niezalogowany_trafia_na_logowanie(): void
    {
        $this->get(route('panel.admin.index'))->assertRedirect(route('login'));
    }

    public function test_administrator_widzi_przeglad(): void
    {
        Server::factory()->count(2)->create();

        $this->actingAs($this->admin)
            ->get(route('panel.admin.index'))
            ->assertOk()
            ->assertSee('Administracja')
            ->assertSee('Flota');
    }

    public function test_wszystkie_strony_administracji_sie_renderuja(): void
    {
        // Szablony Blade nie mają kompilacji na etapie budowania — literówka w
        // dyrektywie wychodzi dopiero przy renderowaniu. Ten test przechodzi
        // każdą stronę z danymi, żeby taki błąd nie trafił na produkcję.
        $hypervisor = Hypervisor::factory()->create(['enrolled_at' => now()]);
        Server::factory()->create(['hypervisor_id' => $hypervisor->id]);
        VpsPackage::factory()->create();
        OsTemplate::factory()->create();
        \App\Models\IpPool::factory()->create(['hypervisor_id' => $hypervisor->id]);

        foreach ([
            'panel.admin.index',
            'panel.admin.hypervisors',
            'panel.admin.packages',
            'panel.admin.templates',
            'panel.admin.ip-pools',
            'panel.admin.servers',
        ] as $route) {
            $this->actingAs($this->admin)
                ->get(route($route))
                ->assertOk();
        }
    }

    public function test_widok_hypervisora_pokazuje_polecenie_instalacyjne(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.admin.hypervisors.store'), ['name' => 'node-z-poleceniem']);

        $this->actingAs($this->admin)
            ->get(route('panel.admin.hypervisors'))
            ->assertOk()
            ->assertSee('| sudo bash', escape: false)
            ->assertSee('node-z-poleceniem');
    }

    public function test_link_do_administracji_widoczny_tylko_dla_admina(): void
    {
        $this->actingAs($this->admin)
            ->get(route('panel.dashboard'))
            ->assertSee('Administracja');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->get(route('panel.dashboard'))
            ->assertDontSee('Administracja');
    }

    // --- hypervisory --------------------------------------------------------

    public function test_dodanie_wezla_wymaga_tylko_nazwy(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.admin.hypervisors.store'), ['name' => 'node-nowy'])
            ->assertRedirect();

        $this->assertDatabaseHas('hypervisors', ['name' => 'node-nowy']);
    }

    public function test_nazwa_wezla_musi_byc_unikalna(): void
    {
        Hypervisor::factory()->create(['name' => 'node1']);

        $this->actingAs($this->admin)
            ->post(route('panel.admin.hypervisors.store'), ['name' => 'node1'])
            ->assertSessionHasErrors('name');
    }

    public function test_nie_da_sie_usunac_wezla_z_maszynami(): void
    {
        $hypervisor = Hypervisor::factory()->create();
        Server::factory()->create(['hypervisor_id' => $hypervisor->id]);

        $this->actingAs($this->admin)
            ->delete(route('panel.admin.hypervisors.destroy', $hypervisor))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('hypervisors', ['id' => $hypervisor->id]);
    }

    public function test_zmiana_pojemnosci_wezla(): void
    {
        $hypervisor = Hypervisor::factory()->create(['enrolled_at' => now()]);

        $this->actingAs($this->admin)
            ->put(route('panel.admin.hypervisors.update', $hypervisor), [
                'cpu_cores_total' => 48,
                'ram_mb_total' => 131072,
                'disk_gb_total' => 4000,
                'bridge' => 'br1',
                'status' => 'maintenance',
                'accepts_new_servers' => '0',
            ])
            ->assertSessionHasNoErrors();

        $hypervisor->refresh();
        $this->assertSame(48, $hypervisor->cpu_cores_total);
        $this->assertSame('br1', $hypervisor->bridge);
        $this->assertSame('maintenance', $hypervisor->status);
        $this->assertFalse($hypervisor->accepts_new_servers);
    }

    // --- oferta -------------------------------------------------------------

    public function test_dodanie_pakietu(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.admin.packages.store'), [
                'name' => 'Business Plus',
                'vcpu' => 4,
                'ram_mb' => 8192,
                'disk_gb' => 100,
                'bandwidth_gb' => 4000,
                'ip_count' => 1,
                'price_hint' => 99.50,
            ])
            ->assertSessionHasNoErrors();

        $package = VpsPackage::where('slug', 'business-plus')->firstOrFail();
        $this->assertSame(9950, $package->price_hint_cents, 'Cena trafia do bazy w groszach');
        $this->assertTrue($package->is_active);
    }

    public function test_wycofanie_pakietu_nie_kasuje_go(): void
    {
        $package = VpsPackage::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('panel.admin.packages.toggle', $package));

        $this->assertFalse($package->fresh()->is_active);
        $this->assertDatabaseHas('vps_packages', ['id' => $package->id]);
    }

    public function test_szablon_odrzuca_sciezke_w_nazwie_pliku(): void
    {
        // Wartość ze ścieżką pozwoliłaby agentowi sięgnąć poza katalog szablonów.
        $this->actingAs($this->admin)
            ->post(route('panel.admin.templates.store'), [
                'name' => 'Złośliwy',
                'family' => 'ubuntu',
                'version' => '24.04',
                'image_file' => '../../../etc/passwd',
                'min_disk_gb' => 10,
            ])
            ->assertSessionHasErrors('image_file');

        $this->assertSame(0, OsTemplate::count());
    }

    public function test_szablon_windows_dostaje_instalacje_reczna(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.admin.templates.store'), [
                'name' => 'Windows Server 2022',
                'family' => 'windows',
                'version' => '2022',
                'image_file' => 'windows-2022.qcow2',
                'min_disk_gb' => 40,
            ])
            ->assertSessionHasNoErrors();

        $template = OsTemplate::firstOrFail();
        $this->assertFalse($template->cloud_init_support);
        $this->assertFalse($template->isSelfService());
    }

    // --- adresy -------------------------------------------------------------

    public function test_import_puli_adresow(): void
    {
        $hypervisor = Hypervisor::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('panel.admin.ip-pools.store'), [
                'hypervisor_id' => $hypervisor->id,
                'name' => 'Pula podstawowa',
                'cidr' => '203.0.113.0/24',
                'gateway' => '203.0.113.1',
                'prefix' => 24,
                'range_from' => '203.0.113.10',
                'range_to' => '203.0.113.20',
                'nameservers' => '1.1.1.1, 9.9.9.9',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(11, IpAddress::count());
        $this->assertSame(11, IpAddress::assignable()->count());
    }

    public function test_niepoprawny_cidr_jest_odrzucany(): void
    {
        $hypervisor = Hypervisor::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('panel.admin.ip-pools.store'), [
                'hypervisor_id' => $hypervisor->id,
                'name' => 'Zła pula',
                'cidr' => 'nie-cidr',
                'gateway' => '203.0.113.1',
                'prefix' => 24,
            ])
            ->assertSessionHasErrors('cidr');
    }

    // --- lista maszyn -------------------------------------------------------

    public function test_wyszukiwanie_maszyn_po_kliencie(): void
    {
        $customer = User::factory()->create(['email' => 'szukany@klient.pl']);
        Server::factory()->create(['user_id' => $customer->id, 'hostname' => 'znaleziony.example.com']);
        Server::factory()->create(['hostname' => 'inny.example.com']);

        $this->actingAs($this->admin)
            ->get(route('panel.admin.servers', ['q' => 'szukany@klient.pl']))
            ->assertOk()
            ->assertSee('znaleziony.example.com')
            ->assertDontSee('inny.example.com');
    }
}
