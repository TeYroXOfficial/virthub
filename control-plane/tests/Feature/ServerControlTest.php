<?php

namespace Tests\Feature;

use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerControlTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['job_id' => 'agent-job-1', 'status' => 'queued'], 202)]);

        $this->owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->server = Server::factory()->create([
            'user_id' => $this->owner->id,
            'hypervisor_id' => Hypervisor::factory()->create()->id,
        ]);
    }

    public function test_wlasciciel_moze_sterowac_zasilaniem(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/power", ['action' => 'reboot'])
            ->assertStatus(202)
            ->assertJsonStructure(['message', 'job_id']);

        $this->assertDatabaseHas('server_jobs', [
            'server_id' => $this->server->id,
            'action' => 'power',
        ]);
    }

    public function test_obcy_uzytkownik_nie_widzi_ani_nie_steruje_maszyna(): void
    {
        $intruder = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($intruder)
            ->getJson("/api/v1/servers/{$this->server->id}")
            ->assertForbidden();

        $this->actingAs($intruder)
            ->postJson("/api/v1/servers/{$this->server->id}/power", ['action' => 'force-off'])
            ->assertForbidden();

        $this->assertDatabaseCount('server_jobs', 0);
    }

    public function test_lista_maszyn_pokazuje_tylko_wlasne(): void
    {
        Server::factory()->count(2)->create(); // cudze maszyny

        $this->actingAs($this->owner)
            ->getJson('/api/v1/servers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->server->id);
    }

    public function test_personel_widzi_wszystkie_maszyny(): void
    {
        Server::factory()->count(2)->create();
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);

        $this->actingAs($support)
            ->getJson('/api/v1/servers')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_nieprawidlowa_akcja_zasilania_jest_odrzucana(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/power", ['action' => 'sformatuj'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('action');
    }

    public function test_maszyna_w_trakcie_operacji_odrzuca_kolejne_polecenia(): void
    {
        $this->server->markState(ServerState::Rebuilding);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/power", ['action' => 'stop'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'trwa już operacja'));
    }

    public function test_zawieszona_maszyna_nie_przyjmuje_polecen_klienta(): void
    {
        $this->server->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => 'Nieopłacona faktura.',
        ])->save();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/power", ['action' => 'start'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'zawieszona'));
    }

    public function test_przebudowa_wymaga_potwierdzenia(): void
    {
        $template = OsTemplate::factory()->create();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/rebuild", [
                'template' => $template->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');

        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/rebuild", [
                'template' => $template->id,
                'confirm' => true,
            ])
            ->assertStatus(202);

        $this->assertSame(ServerState::Rebuilding, $this->server->fresh()->state);
    }

    public function test_wsparcie_nie_moze_przebudowac_cudzej_maszyny(): void
    {
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $template = OsTemplate::factory()->create();

        // Support widzi maszynę i zrestartuje ją na zgłoszenie, ale nie skasuje
        // klientowi danych — to zostaje przy administratorze i właścicielu.
        $this->actingAs($support)
            ->postJson("/api/v1/servers/{$this->server->id}/power", ['action' => 'reboot'])
            ->assertStatus(202);

        $this->actingAs($support)
            ->postJson("/api/v1/servers/{$this->server->id}/rebuild", [
                'template' => $template->id,
                'confirm' => true,
            ])
            ->assertForbidden();
    }

    public function test_zmiana_pakietu_wymaga_zatrzymanej_maszyny(): void
    {
        $bigger = \App\Models\VpsPackage::factory()->large()->create(['slug' => 'business']);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/resize", ['package' => 'business'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'zatrzymanej maszyny'));

        $this->server->markState(ServerState::Stopped);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/resize", ['package' => 'business'])
            ->assertStatus(202);

        $this->assertSame(ServerState::Resizing, $this->server->fresh()->state);
        $this->assertGreaterThan(0, ServerJob::where('action', 'resize')->count());
    }

    public function test_nie_da_sie_zmniejszyc_dysku(): void
    {
        $smaller = \App\Models\VpsPackage::factory()->small()->create(['slug' => 'starter']);
        $this->server->markState(ServerState::Stopped);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/resize", ['package' => 'starter'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'zmniejszyć dysku'));
    }

    public function test_haslo_root_pokazuje_sie_tylko_raz(): void
    {
        $this->server->forceFill(['root_password' => 'TajneHaslo123'])->save();

        $this->actingAs($this->owner)
            ->getJson("/api/v1/servers/{$this->server->id}/credentials")
            ->assertOk()
            ->assertJsonPath('password', 'TajneHaslo123')
            ->assertJsonPath('available', true);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/servers/{$this->server->id}/credentials")
            ->assertOk()
            ->assertJsonPath('password', null)
            ->assertJsonPath('available', false);
    }

    public function test_konsola_wymaga_dzialajacej_maszyny(): void
    {
        $this->server->markState(ServerState::Stopped);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/console-token")
            ->assertStatus(409);

        $this->server->markState(ServerState::Running);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/console-token")
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_in', 'url']);
    }

    public function test_usuniecie_maszyny_zleca_zadanie_i_nie_kasuje_od_razu(): void
    {
        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/servers/{$this->server->id}")
            ->assertStatus(202);

        // Rekord znika dopiero, gdy hypervisor potwierdzi skasowanie dysku.
        $this->assertSame(ServerState::Deleting, $this->server->fresh()->state);
        $this->assertNotNull(Server::find($this->server->id));
    }
}
