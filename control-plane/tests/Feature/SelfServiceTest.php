<?php

namespace Tests\Feature;

use App\Domain\Billing\Billing;
use App\Domain\Provisioning\SelfService;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\ResourcePack;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private ResourcePack $pack;

    private OsTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->customer = User::factory()->create();
        $node = Hypervisor::factory()->create(['cpu_cores_total' => 64, 'ram_mb_total' => 131072, 'disk_gb_total' => 4000]);
        $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
        IpAddress::factory()->count(5)->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id]);
        $this->template = OsTemplate::factory()->create();
        $base = VpsPackage::factory()->create(['vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 10, 'ip_count' => 1, 'bandwidth_gb' => 1000]);
        $this->pack = $this->customer->resourcePacks()->create([
            'name' => 'Cloud', 'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 100, 'max_servers' => 3, 'vps_package_id' => $base->id,
        ]);
    }

    private function build(array $over = [])
    {
        return $this->actingAs($this->customer)->post(route('panel.self-service.store', $this->pack), array_merge([
            'template' => $this->template->id, 'hostname' => 'ss1.example.test', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
        ], $over));
    }

    public function test_klient_buduje_maszyne_wlasnej_wielkosci(): void
    {
        $this->actingAs($this->customer)->get(route('panel.self-service'))->assertOk()->assertSee('Cloud');
        $this->build()->assertSessionHasNoErrors()->assertRedirect();

        $server = Server::firstOrFail();
        $this->assertSame([2, 4096, 40, 1000], [$server->vcpu, $server->ram_mb, $server->disk_gb, $server->bandwidth_gb]);
        $this->assertSame($this->pack->id, $server->resource_pack_id);
        $this->assertSame(['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'servers' => 1], $this->pack->used());

        // Ponad budżet — odrzucone.
        $this->build(['hostname' => 'ss2.example.test', 'vcpu' => 3])->assertSessionHasErrors('pack');
        $this->build(['hostname' => 'ss2.example.test', 'disk_gb' => 61])->assertSessionHasErrors('pack');
        $this->build(['hostname' => 'ss2.example.test', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 60])->assertSessionHasNoErrors();
        $this->assertSame(2, Server::count());
    }

    public function test_bez_pakietu_brak_strony_i_cudzy_pakiet(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('panel.self-service'))->assertNotFound();
        $this->actingAs($other)->post(route('panel.self-service.store', $this->pack), ['template' => $this->template->id, 'hostname' => 'x.test', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 10])
            ->assertNotFound();
    }

    public function test_zwykla_maszyna_chroniona_przed_usunieciem_z_samoobslugi(): void
    {
        $traditional = Server::factory()->create(['user_id' => $this->customer->id]);
        $this->actingAs($this->customer)->delete(route('panel.self-service.destroy', $traditional))->assertSessionHasErrors('pack');
        $this->assertSame(0, ServerJob::where('action', 'delete')->count());

        $this->build();
        $own = Server::where('resource_pack_id', $this->pack->id)->firstOrFail();
        $this->actingAs($this->customer)->delete(route('panel.self-service.destroy', $own))->assertRedirect(route('panel.self-service'));
        $this->assertSame(1, ServerJob::where('action', 'delete')->where('server_id', $own->id)->count());
    }

    public function test_rozliczenie_godzinowe_i_zawieszenie(): void
    {
        Billing::save(['enabled' => true, 'currency' => 'PLN']);
        $this->pack->update(['price_vcpu_hour' => 100, 'price_ram_gb_hour' => 50, 'price_disk_gb_hour' => 1]);
        $this->customer->forceFill(['wallet_balance' => 10000])->save();

        // Godzina 2 vCPU + 4 GB + 40 GB = 200 + 200 + 40 = 440 gr.
        $this->assertSame(440, $this->pack->hourlyCost(2, 4096, 40));
        $this->build()->assertSessionHasNoErrors();
        $billing = app(SelfService::class);

        $this->assertSame(0, $billing->billPack($this->pack->fresh()));
        $this->travel(3)->hours();
        $this->assertSame(3, $billing->billPack($this->pack->fresh()));
        $this->assertSame(10000 - 3 * 440, $this->customer->fresh()->wallet_balance);

        // Saldo na wyczerpaniu — zawieszenie maszyn pakietu.
        $this->customer->forceFill(['wallet_balance' => 500])->save();
        $this->travel(2)->hours();
        $billing->billPack($this->pack->fresh());
        $this->assertNotNull($this->pack->fresh()->suspended_at);
        $this->assertTrue(Server::firstOrFail()->isSuspended());
        $this->build(['hostname' => 'ss9.example.test', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 10])->assertSessionHasErrors('pack');

        // Doładowanie — maszyny wracają.
        $this->customer->forceFill(['wallet_balance' => 5000])->save();
        $billing->billPack($this->pack->fresh());
        $this->assertNull($this->pack->fresh()->suspended_at);
        $this->assertFalse(Server::firstOrFail()->isSuspended());
    }

    public function test_administrator_przydziela_pakiet(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $target = User::factory()->create(['email' => 'nowy@example.test']);
        $this->actingAs($admin)->get(route('panel.admin.resource-packs'))->assertOk()->assertSee('Cloud');
        $this->post(route('panel.admin.resource-packs.store'), ['email' => 'brak@example.test', 'name' => 'X', 'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 20, 'max_servers' => 2, 'vps_package_id' => $this->pack->vps_package_id])
            ->assertSessionHasErrors('email');
        $this->post(route('panel.admin.resource-packs.store'), ['email' => 'nowy@example.test', 'name' => 'Mały', 'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 20, 'max_servers' => 2, 'vps_package_id' => $this->pack->vps_package_id, 'is_active' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $target->resourcePacks()->count());

        $this->actingAs($this->customer)->get(route('panel.admin.resource-packs'))->assertForbidden();
    }
}
