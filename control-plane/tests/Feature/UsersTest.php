<?php

namespace Tests\Feature;

use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, ...$attributes]);
    }

    private function serverOf(User $owner): Server
    {
        $server = Server::factory()->create([
            'user_id' => $owner->id,
            'hypervisor_id' => Hypervisor::factory()->create()->id,
            'agent_uuid' => '11111111-2222-3333-4444-555555555555',
        ]);
        $server->markState(ServerState::Running);

        return $server;
    }

    // --- zakładanie kont ------------------------------------------------------

    public function test_administrator_zaklada_konto_z_wygenerowanym_haslem(): void
    {
        $response = $this->actingAs($this->admin)->post(route('panel.admin.users.store'), [
            'name' => 'Jan Nowak',
            'email' => 'Jan@Example.com',
            'role' => 'customer',
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'jan@example.com')->firstOrFail();
        $password = session('generated_password');

        $this->assertNotEmpty($password);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNull($user->permissions, 'Bez własnych uprawnień — domyślne roli');
        $response->assertRedirect(route('panel.admin.users.edit', $user));
    }

    public function test_wlasne_uprawnienia_i_limity(): void
    {
        $package = VpsPackage::factory()->create();

        $this->actingAs($this->admin)->post(route('panel.admin.users.store'), [
            'name' => 'Ograniczony',
            'email' => 'ogr@example.com',
            'role' => 'customer',
            'password' => 'bardzo-dlugie-haslo',
            'custom_permissions' => 1,
            // admin.hypervisors musi zostać odrzucone — klient nie może go mieć.
            'permissions' => ['servers.power', 'admin.hypervisors'],
            'max_servers' => 2,
            'restrict_packages' => 1,
            'allowed_package_ids' => [$package->id],
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'ogr@example.com')->firstOrFail();
        $this->assertSame(['servers.power'], $user->effectivePermissions());
        $this->assertSame(2, $user->serverLimit());
        $this->assertTrue($user->mayOrderPackage($package));
        $this->assertFalse($user->mayOrderPackage(VpsPackage::factory()->create()));
    }

    public function test_support_zarzadza_tylko_klientami(): void
    {
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);

        $this->actingAs($support)->post(route('panel.admin.users.store'), [
            'name' => 'Admin 2', 'email' => 'a2@example.com', 'role' => 'admin',
        ])->assertSessionHasErrors('role');

        $this->actingAs($support)->get(route('panel.admin.users.edit', $this->admin))->assertForbidden();
        $this->actingAs($support)->post(route('panel.admin.users.suspend', $this->admin))->assertForbidden();

        $customer = $this->customer();
        $this->actingAs($support)->get(route('panel.admin.users.edit', $customer))->assertOk();
    }

    public function test_nie_mozna_zablokowac_siebie_ani_ostatniego_administratora(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.admin.users.suspend', $this->admin))
            ->assertSessionHasErrors('user');

        $this->actingAs($this->admin)->put(route('panel.admin.users.update', $this->admin), [
            'name' => 'Admin', 'email' => $this->admin->email, 'role' => 'customer',
        ])->assertSessionHasErrors('role');

        $this->assertTrue($this->admin->fresh()->isAdmin());
    }

    public function test_usuniecie_konta_z_maszynami_jest_blokowane(): void
    {
        $customer = $this->customer();
        $this->serverOf($customer);

        $this->actingAs($this->admin)
            ->delete(route('panel.admin.users.destroy', $customer))
            ->assertSessionHasErrors('user');

        $this->assertModelExists($customer);
    }

    public function test_blokada_konta_wylogowuje_i_uniewaznia_tokeny(): void
    {
        $customer = $this->customer();
        $customer->createToken('api');

        $this->actingAs($this->admin)->post(route('panel.admin.users.suspend', $customer))->assertSessionHasNoErrors();

        $this->assertSame(0, $customer->tokens()->count());
        $this->actingAs($customer->fresh())->get(route('panel.dashboard'))->assertRedirect(route('login'));
    }

    public function test_reset_hasla(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin)->post(route('panel.admin.users.password', $customer));

        $this->assertTrue(Hash::check(session('generated_password'), $customer->fresh()->password));
    }

    // --- egzekwowanie uprawnień klienta -------------------------------------------

    public function test_bez_uprawnienia_zasilania_i_konsoli(): void
    {
        $customer = $this->customer(['permissions' => ['servers.firewall']]);
        $server = $this->serverOf($customer);

        $this->actingAs($customer)
            ->postJson("/api/v1/servers/{$server->id}/power", ['action' => 'reboot'])
            ->assertForbidden();
        $this->actingAs($customer)->post(route('panel.servers.console', $server))->assertForbidden();

        $this->actingAs($customer)->get(route('panel.servers.show', $server))
            ->assertOk()
            ->assertDontSee('id="power-controls"', false)
            ->assertDontSee('Konsola</button>', false);

        // Zapora dozwolona.
        $this->actingAs($customer)->post(route('panel.servers.firewall.store', $server), [
            'action' => 'accept', 'direction' => 'in', 'protocol' => 'tcp', 'port_from' => 22,
        ])->assertSessionHasNoErrors();
    }

    public function test_bez_uprawnienia_zamawiania(): void
    {
        $customer = $this->customer(['permissions' => ['servers.power']]);

        $this->actingAs($customer)->get(route('panel.servers.create'))->assertForbidden();
        $this->actingAs($customer)->get(route('panel.dashboard'))->assertOk()->assertDontSee('Zamów serwer');
    }

    public function test_limit_maszyn_obowiazuje_tez_w_api(): void
    {
        $customer = $this->customer(['max_servers' => 1]);
        $this->serverOf($customer);
        $package = VpsPackage::factory()->create(['slug' => 'std']);

        $this->actingAs($customer)->postJson('/api/v1/servers', [
            'package' => 'std',
            'template' => OsTemplate::factory()->create()->id,
            'hostname' => 'vps2.example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('package');
    }

    public function test_niedozwolony_pakiet(): void
    {
        $node = Hypervisor::factory()->create();
        $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
        IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id]);
        $allowed = VpsPackage::factory()->create(['slug' => 'ok']);
        VpsPackage::factory()->create(['slug' => 'premium', 'name' => 'Premium']);
        $customer = $this->customer(['allowed_package_ids' => [$allowed->id]]);
        $template = OsTemplate::factory()->create();

        $this->actingAs($customer)->postJson('/api/v1/servers', [
            'package' => 'premium', 'template' => $template->id, 'hostname' => 'a.example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('package');

        $this->actingAs($customer)->get(route('panel.servers.create'))
            ->assertOk()
            ->assertDontSee('Premium');

        $this->actingAs($customer)->postJson('/api/v1/servers', [
            'package' => 'ok', 'template' => $template->id, 'hostname' => 'b.example.com',
        ])->assertCreated();
    }

    // --- personel -----------------------------------------------------------------

    public function test_support_widzi_tylko_przydzielone_dzialy(): void
    {
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);

        $this->actingAs($support)->get(route('panel.admin.servers'))->assertOk();
        $this->actingAs($support)->get(route('panel.admin.users'))->assertOk();
        $this->actingAs($support)->get(route('panel.admin.hypervisors'))->assertForbidden();
        $this->actingAs($support)->get(route('panel.admin.updates'))->assertForbidden();

        $support->forceFill(['permissions' => ['admin.hypervisors']])->save();
        $this->actingAs($support->fresh())->get(route('panel.admin.hypervisors'))->assertOk();
        $this->actingAs($support->fresh())->get(route('panel.admin.servers'))->assertForbidden();
    }

    public function test_support_nie_usunie_cudzej_maszyny(): void
    {
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $server = $this->serverOf($this->customer());

        $this->actingAs($support)
            ->postJson("/api/v1/servers/{$server->id}/power", ['action' => 'reboot'])
            ->assertStatus(202);
        $this->actingAs($support)
            ->deleteJson("/api/v1/servers/{$server->id}")
            ->assertForbidden();
    }

    public function test_klient_nie_wejdzie_do_administracji(): void
    {
        $customer = $this->customer(['permissions' => ['admin.users']]);

        $this->actingAs($customer)->get(route('panel.admin.users'))->assertForbidden();
        $this->actingAs($customer)->get(route('panel.admin.index'))->assertForbidden();
    }
}
