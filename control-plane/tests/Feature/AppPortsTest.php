<?php

namespace Tests\Feature;

use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\EggImporter;
use App\Models\AppAllocation;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Zakładka „Sieć”: porty aplikacji — limit planu, główny port, notatki, uprawnienia. */
class AppPortsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private AppServer $gameApp;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(['*' => Http::response(['restart_required' => true])]);
        $node = Hypervisor::factory()->create([
            'apps_enabled' => true, 'app_port_start' => 25565, 'app_port_end' => 25574, 'ram_mb_total' => 16384, 'ram_mb_used' => 0,
            'disk_gb_total' => 500, 'disk_gb_used' => 0, 'last_health' => ['apps' => ['available' => true, 'docker_version' => '29.0'], 'public_ipv4' => '203.0.113.10'],
        ]);
        $plan = AppPlan::query()->create(['name' => 'Gra S', 'memory_mb' => 2048, 'disk_mb' => 10240, 'ports' => 2]);
        app(EggImporter::class)->importBuiltin();
        $this->customer = User::factory()->create();
        $this->gameApp = app(AppProvisioner::class)->order($this->customer, AppEgg::query()->where('builtin_key', 'minecraft-paper')->firstOrFail(), $plan, 'Survival');
        $this->gameApp->forceFill(['status' => AppServer::STATUS_READY])->save();
    }

    public function test_klient_zarzadza_portami_w_limicie_planu(): void
    {
        $this->assertSame([25565, 25566], $this->gameApp->allocations()->pluck('port')->all());
        $this->actingAs($this->customer)->get(route('panel.apps.network', $this->gameApp))->assertOk()
            ->assertSee('203.0.113.10:25565')->assertSee('2 z 2');

        // Limit planu: 2 porty.
        $this->actingAs($this->customer)->post(route('panel.apps.ports.store', $this->gameApp))->assertSessionHasErrors('port');

        $second = AppAllocation::query()->where('port', 25566)->firstOrFail();
        $this->actingAs($this->customer)->put(route('panel.apps.ports.note', [$this->gameApp, $second]), ['notes' => 'RCON'])->assertRedirect();
        $this->assertSame('RCON', $second->fresh()->notes);

        // Nowy główny port → specyfikacja na węźle, komunikat o restarcie.
        $this->actingAs($this->customer)->post(route('panel.apps.ports.primary', [$this->gameApp, $second]))->assertSessionHas('status');
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame('203.0.113.10:25566', $this->gameApp->fresh()->address());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), $this->gameApp->uuid) && $r['allocations'][0]['port'] === 25566);

        // Głównego nie da się usunąć; pozostały — tak, i wtedy można dodać kolejny.
        $this->actingAs($this->customer)->delete(route('panel.apps.ports.destroy', [$this->gameApp, $second]))->assertSessionHasErrors('port');
        $first = AppAllocation::query()->where('port', 25565)->firstOrFail();
        $this->actingAs($this->customer)->delete(route('panel.apps.ports.destroy', [$this->gameApp, $first]))->assertSessionHasNoErrors();
        $this->assertNull(AppAllocation::query()->where('port', 25565)->value('app_server_id'));
        $this->actingAs($this->customer)->post(route('panel.apps.ports.store', $this->gameApp))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->gameApp->allocations()->count());

        // Klient nie wybiera numeru portu ani nie zmienia limitu.
        $this->actingAs($this->customer)->put(route('panel.apps.ports.limit', $this->gameApp), ['port_limit' => 10])->assertForbidden();
    }

    public function test_personel_dodaje_konkretny_port_ponad_limit(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->post(route('panel.apps.ports.store', $this->gameApp), ['port' => 25570])->assertSessionHasNoErrors();
        $this->assertTrue($this->gameApp->allocations()->where('port', 25570)->exists());
        $this->actingAs($admin)->post(route('panel.apps.ports.store', $this->gameApp), ['port' => 25570])->assertSessionHasErrors('port');
        $this->actingAs($admin)->post(route('panel.apps.ports.store', $this->gameApp), ['port' => 30000])->assertSessionHasErrors('port');

        $this->actingAs($admin)->put(route('panel.apps.ports.limit', $this->gameApp), ['port_limit' => 5])->assertRedirect();
        $this->assertSame(5, $this->gameApp->fresh()->port_limit);
        $this->actingAs($this->customer)->post(route('panel.apps.ports.store', $this->gameApp))->assertSessionHasNoErrors();
        $this->assertSame(4, $this->gameApp->allocations()->count());

        // Cudza aplikacja.
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('panel.apps.network', $this->gameApp))->assertForbidden();
    }
}
