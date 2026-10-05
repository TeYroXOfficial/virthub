<?php

namespace Tests\Feature;

use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\EggImporter;
use App\Domain\Billing\Billing;
use App\Domain\Provisioning\AgentResultApplier;
use App\Enums\ServerState;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Edycja istniejących pakietów VPS i planów aplikacji. */
class PlanEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function packageForm(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'VPS M', 'vcpu' => 4, 'cpu_limit_percent' => '', 'ram_mb' => 8192, 'disk_gb' => 80,
            'bandwidth_gb' => 4000, 'network_type' => 'public', 'ip_count' => 1, 'ipv6_count' => 1, 'price_hint' => '59.90',
        ];
    }

    public function test_edycja_pakietu_nie_zmienia_dzialajacych_maszyn_ani_adresu(): void
    {
        $package = VpsPackage::factory()->create(['name' => 'VPS S', 'slug' => 'vps-s', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40]);
        $server = Server::factory()->create(['vps_package_id' => $package->id, 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40]);

        $this->actingAs($this->admin)->get(route('panel.admin.packages'))->assertOk()->assertSee('pkg-edit-'.$package->id);
        $this->actingAs($this->admin)->put(route('panel.admin.packages.update', $package), $this->packageForm())
            ->assertRedirect()->assertSessionHas('status');

        $package->refresh();
        $this->assertSame(['VPS M', 4, 8192, 80, 1, 5990, 'vps-s'], [$package->name, $package->vcpu, $package->ram_mb, $package->disk_gb, $package->ipv6_count, $package->price_hint_cents, $package->slug]);
        $this->assertNull($package->cpu_limit_percent);
        $this->assertSame([2, 4096, 40], [$server->fresh()->vcpu, $server->fresh()->ram_mb, $server->fresh()->disk_gb]);
    }

    public function test_bledy_edycji_pakietu_trafiaja_do_jego_okna(): void
    {
        $package = VpsPackage::factory()->create(['name' => 'VPS S', 'vcpu' => 2]);

        $this->actingAs($this->admin)->from(route('panel.admin.packages'))
            ->put(route('panel.admin.packages.update', $package), $this->packageForm(['vcpu' => 2, 'cpu_limit_percent' => 500]))
            ->assertRedirect(route('panel.admin.packages'))->assertSessionHasErrorsIn('edit_'.$package->id, 'cpu_limit_percent');
        $this->assertSame('VPS S', $package->fresh()->name);

        $this->actingAs($this->admin)->get(route('panel.admin.packages'))->assertOk()->assertSee('data-open', false);
    }

    public function test_edycja_planu_z_przeniesieniem_na_aplikacje(): void
    {
        $node = Hypervisor::factory()->create([
            'apps_enabled' => true, 'app_port_start' => 25565, 'app_port_end' => 25574, 'ram_mb_total' => 16384, 'ram_mb_used' => 0,
            'disk_gb_total' => 500, 'disk_gb_used' => 0, 'last_health' => ['apps' => ['available' => true, 'docker_version' => '29.0'], 'public_ipv4' => '203.0.113.10'],
        ]);
        $plan = AppPlan::query()->create(['name' => 'Gra S', 'memory_mb' => 2048, 'cpu_percent' => 100, 'disk_mb' => 10240, 'ports' => 1]);
        app(EggImporter::class)->importBuiltin();
        $customer = User::factory()->create();
        $app = app(AppProvisioner::class)->order($customer, AppEgg::query()->where('builtin_key', 'minecraft-paper')->firstOrFail(), $plan, 'Survival');
        $app->forceFill(['status' => AppServer::STATUS_READY])->save();

        // Bez zaznaczenia — tylko nowe aplikacje.
        $form = ['name' => 'Gra M', 'memory_mb' => 4096, 'cpu_percent' => 200, 'disk_mb' => 20480, 'ports' => 2, 'price_hint' => ''];
        $this->actingAs($this->admin)->put(route('panel.admin.apps.plans.update', $plan), $form)->assertRedirect();
        $this->assertSame(['Gra M', 4096, 200, 20480, 2], [$plan->fresh()->name, $plan->fresh()->memory_mb, $plan->fresh()->cpu_percent, $plan->fresh()->disk_mb, $plan->fresh()->ports]);
        $this->assertSame(2048, $app->fresh()->memory_mb);

        // Z przeniesieniem — panel i węzeł dostają nowe limity.
        Http::fake(['*' => Http::sequence()
            ->push(['restart_required' => false])
            ->whenEmpty(Http::response(['detail' => 'down'], 503))]);
        $this->actingAs($this->admin)->put(route('panel.admin.apps.plans.update', $plan), $form + ['apply_existing' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([4096, 200, 20480], [$app->fresh()->memory_mb, $app->fresh()->cpu_percent, $app->fresh()->disk_mb]);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), $app->uuid));
        $this->assertSame($node->id, $app->fresh()->hypervisor_id);

        // Węzeł nie odpowiada — limity zapisane w panelu, administrator dostaje ostrzeżenie.
        $this->actingAs($this->admin)->put(route('panel.admin.apps.plans.update', $plan), ['memory_mb' => 3072] + $form + ['apply_existing' => 1])
            ->assertRedirect()->assertSessionHasErrors('plan');
        $this->assertSame(3072, $app->fresh()->memory_mb);
    }

    public function test_zmiana_pakietu_maszyny_przenosi_nowe_parametry(): void
    {
        $package = VpsPackage::factory()->create(['name' => 'VPS S', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'bandwidth_gb' => 1000]);
        $server = Server::factory()->create(['vps_package_id' => $package->id, 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'state' => ServerState::Stopped]);
        $this->actingAs($this->admin)->put(route('panel.admin.packages.update', $package), $this->packageForm(['name' => 'VPS S']));

        $this->actingAs($this->admin)->get(route('panel.servers.show', $server))->assertOk()
            ->assertSee('pakiet ma inne parametry')->assertSee(route('panel.servers.resize', $server));
        $this->actingAs($this->admin)->post(route('panel.servers.resize', $server), ['package' => $package->id])
            ->assertRedirect(route('panel.servers.show', $server))->assertSessionHasNoErrors();

        $job = ServerJob::query()->where('action', 'resize')->firstOrFail();
        $this->assertSame([4, 8192, 80], [$job->payload['vcpu'], $job->payload['ram_mb'], $job->payload['disk_gb']]);
        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'done']);
        $server->refresh();
        $this->assertSame([4, 8192, 80, 4000], [$server->vcpu, $server->ram_mb, $server->disk_gb, $server->bandwidth_gb]);
    }

    public function test_zmiana_pakietu_wymaga_zatrzymania_i_nie_jest_darmowa_z_billingiem(): void
    {
        $small = VpsPackage::factory()->create(['vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20]);
        $big = VpsPackage::factory()->create(['vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80]);
        $customer = User::factory()->create();
        $server = Server::factory()->create(['user_id' => $customer->id, 'vps_package_id' => $small->id, 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20, 'state' => ServerState::Running]);

        $this->actingAs($customer)->post(route('panel.servers.resize', $server), ['package' => $big->id])->assertSessionHasErrors('package');

        Billing::save(['enabled' => true]);
        $server->forceFill(['state' => ServerState::Stopped])->save();
        $this->actingAs($customer)->post(route('panel.servers.resize', $server), ['package' => $big->id])->assertForbidden();
        $this->actingAs($customer)->postJson('/api/v1/servers/'.$server->id.'/resize', ['package' => $big->slug])->assertForbidden();
        $this->actingAs($customer)->get(route('panel.servers.show', $server))->assertOk()->assertDontSee(route('panel.servers.resize', $server));
        $this->assertSame(0, ServerJob::query()->where('action', 'resize')->count());
    }

    public function test_edycja_wymaga_uprawnien(): void
    {
        $package = VpsPackage::factory()->create();
        $plan = AppPlan::query()->create(['name' => 'X', 'memory_mb' => 1024, 'disk_mb' => 2048, 'ports' => 1]);
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT, 'permissions' => ['admin.servers']]);

        $this->actingAs($support)->put(route('panel.admin.packages.update', $package), $this->packageForm())->assertForbidden();
        $this->actingAs($support)->put(route('panel.admin.apps.plans.update', $plan), ['name' => 'Y', 'memory_mb' => 1024, 'disk_mb' => 2048, 'ports' => 1])->assertForbidden();
    }
}
