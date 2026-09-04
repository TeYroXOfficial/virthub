<?php

namespace Tests\Feature;

use App\Enums\ServerState;
use App\Jobs\ProvisionServerJob;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServerProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Hypervisor $hypervisor;

    private VpsPackage $package;

    private OsTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->hypervisor = Hypervisor::factory()->create([
            'cpu_cores_total' => 16,
            'ram_mb_total' => 32768,
            'disk_gb_total' => 500,
        ]);
        $this->package = VpsPackage::factory()->create([
            'slug' => 'standard',
            'vcpu' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 50,
            'ip_count' => 1,
        ]);
        $this->template = OsTemplate::factory()->create();

        $pool = IpPool::factory()->create(['hypervisor_id' => $this->hypervisor->id]);
        IpAddress::factory()->count(3)->create([
            'ip_pool_id' => $pool->id,
            'hypervisor_id' => $this->hypervisor->id,
        ]);
    }

    public function test_zamowienie_tworzy_maszyne_i_rezerwuje_zasoby(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->customer)->postJson('/api/v1/servers', [
            'package' => 'standard',
            'template' => $this->template->id,
            'hostname' => 'vps1.example.com',
            'ssh_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest test@host'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.hostname', 'vps1.example.com')
            ->assertJsonPath('data.state', 'building');

        $server = Server::firstOrFail();
        $this->assertSame($this->hypervisor->id, $server->hypervisor_id);
        $this->assertSame(2, $server->vcpu, 'Parametry mają być skopiowane z pakietu');

        // Zasoby zarezerwowane od razu, nie po zakończeniu provisioningu —
        // inaczej równoległe zamówienia zobaczyłyby to samo wolne miejsce.
        $this->hypervisor->refresh();
        $this->assertSame(2, $this->hypervisor->cpu_cores_used);
        $this->assertSame(4096, $this->hypervisor->ram_mb_used);
        $this->assertSame(50, $this->hypervisor->disk_gb_used);

        $this->assertSame(1, $server->ipAddresses()->count());
        $this->assertTrue($server->ipAddresses()->first()->is_primary);

        Queue::assertPushed(ProvisionServerJob::class);
    }

    public function test_zadanie_provisioningu_wysyla_zlecenie_do_agenta(): void
    {
        Http::fake([
            '*/vm' => Http::response(['job_id' => 'agent-job-1', 'status' => 'queued'], 202),
        ]);

        $this->actingAs($this->customer)->postJson('/api/v1/servers', [
            'package' => 'standard',
            'template' => $this->template->id,
            'hostname' => 'vps2.example.com',
        ])->assertCreated();

        $job = ServerJob::where('action', 'create')->firstOrFail();
        $this->assertSame('agent-job-1', $job->agent_job_id);
        $this->assertSame(ServerJob::STATUS_RUNNING, $job->status);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/vm')
                && $body['hostname'] === 'vps2.example.com'
                && $body['vcpu'] === 2
                && $body['interfaces'][0]['address'] === $body['interfaces'][0]['address']
                // Podpis jest obowiązkowy — agent odrzuci żądanie bez niego.
                && $request->hasHeader('X-VH-Signature')
                && $request->hasHeader('X-VH-Timestamp');
        });
    }

    public function test_nieudane_zlecenie_zwalnia_zasoby_i_adres(): void
    {
        // 409 od agenta nie jest błędem przejściowym — nie ma czego ponawiać.
        Http::fake([
            '*/vm' => Http::response(['detail' => 'Brak miejsca na dysku hosta.'], 409),
        ]);

        $this->actingAs($this->customer)->postJson('/api/v1/servers', [
            'package' => 'standard',
            'template' => $this->template->id,
            'hostname' => 'vps3.example.com',
        ])->assertCreated();

        $server = Server::firstOrFail();
        $this->assertSame(ServerState::Error, $server->state);
        $this->assertStringContainsString('Brak miejsca', $server->state_message);

        $this->hypervisor->refresh();
        $this->assertSame(0, $this->hypervisor->cpu_cores_used, 'Zasoby muszą wrócić do puli');
        $this->assertSame(0, $server->ipAddresses()->count(), 'Adres musi wrócić do puli');
        $this->assertSame(3, IpAddress::whereNull('server_id')->count());
    }

    public function test_brak_pojemnosci_konczy_sie_czytelnym_komunikatem(): void
    {
        Hypervisor::query()->update([
            'cpu_cores_used' => 16,
            'ram_mb_used' => 32768,
            'disk_gb_used' => 500,
        ]);

        $response = $this->actingAs($this->customer)->postJson('/api/v1/servers', [
            'package' => 'standard',
            'template' => $this->template->id,
            'hostname' => 'vps4.example.com',
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Brak hypervisora'));

        $this->assertSame(0, Server::count(), 'Nieudane zamówienie nie może zostawić rekordu');
    }

    public function test_wyczerpana_pula_adresow_nie_tworzy_maszyny(): void
    {
        IpAddress::query()->update(['is_reserved' => true]);

        $this->actingAs($this->customer)->postJson('/api/v1/servers', [
            'package' => 'standard',
            'template' => $this->template->id,
            'hostname' => 'vps5.example.com',
        ])->assertStatus(503)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'wyczerpana'));

        $this->hypervisor->refresh();
        $this->assertSame(0, $this->hypervisor->cpu_cores_used,
            'Wycofana transakcja nie może zostawić zarezerwowanych zasobów');
    }

    public function test_szablon_bez_cloud_init_nie_da_sie_zamowic(): void
    {
        $windows = OsTemplate::factory()->manualInstall()->create();

        $this->actingAs($this->customer)->postJson('/api/v1/servers', [
            'package' => 'standard',
            'template' => $windows->id,
            'hostname' => 'win.example.com',
        ])->assertStatus(500); // InvalidArgumentException — świadomie niedostępna ścieżka

        $this->assertSame(0, Server::count());
    }

    public function test_pakiet_z_za_malym_dyskiem_jest_odrzucany(): void
    {
        $template = OsTemplate::factory()->create(['min_disk_gb' => 100]);

        $this->actingAs($this->customer)->postJson('/api/v1/servers', [
            'package' => 'standard', // 50 GB
            'template' => $template->id,
            'hostname' => 'vps6.example.com',
        ])->assertStatus(500);

        $this->assertSame(0, Server::count());
    }

    public function test_niepoprawna_nazwa_hosta_jest_odrzucana(): void
    {
        $this->actingAs($this->customer)->postJson('/api/v1/servers', [
            'package' => 'standard',
            'template' => $this->template->id,
            'hostname' => 'to nie jest nazwa hosta!',
        ])->assertStatus(422)->assertJsonValidationErrors('hostname');
    }

    public function test_gosc_nie_moze_zamowic_maszyny(): void
    {
        $this->postJson('/api/v1/servers', [
            'package' => 'standard',
            'template' => $this->template->id,
            'hostname' => 'vps7.example.com',
        ])->assertUnauthorized();
    }
}
