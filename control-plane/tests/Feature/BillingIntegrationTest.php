<?php

namespace Tests\Feature;

use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private OsTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['job_id' => 'agent-job-1', 'status' => 'queued'], 202)]);

        $hypervisor = Hypervisor::factory()->create();
        $pool = IpPool::factory()->create(['hypervisor_id' => $hypervisor->id]);
        IpAddress::factory()->count(5)->create([
            'ip_pool_id' => $pool->id,
            'hypervisor_id' => $hypervisor->id,
        ]);

        VpsPackage::factory()->create(['slug' => 'standard']);
        $this->template = OsTemplate::factory()->create();
    }

    private function actingAsBilling(): void
    {
        // Token integracji ma jedno uprawnienie i nie może nic poza nim —
        // wyciek klucza z systemu rozliczeniowego nie daje dostępu do panelu.
        Sanctum::actingAs(
            User::factory()->create(['role' => User::ROLE_ADMIN]),
            ['billing'],
        );
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'billing_reference' => 'INV-2026-001',
            'customer_email' => 'nowy@klient.pl',
            'customer_name' => 'Nowy Klient',
            'package' => 'standard',
            'template' => $this->template->id,
            'hostname' => 'usluga.example.com',
        ], $overrides);
    }

    public function test_provisioning_zaklada_konto_i_maszyne(): void
    {
        $this->actingAsBilling();

        $this->postJson('/api/v1/billing/provision', $this->payload([
            'hostname' => 'usluga1.example.com',
        ]))->assertCreated()->assertJsonStructure(['server_id', 'customer_id', 'state']);

        $user = User::where('email', 'nowy@klient.pl')->firstOrFail();
        $this->assertSame(User::ROLE_CUSTOMER, $user->role);

        $server = Server::firstOrFail();
        $this->assertSame('INV-2026-001', $server->billing_reference);
        $this->assertSame($user->id, $server->user_id);
    }

    public function test_powtorzony_webhook_nie_tworzy_drugiej_maszyny(): void
    {
        $this->actingAsBilling();
        $payload = $this->payload(['hostname' => 'usluga2.example.com']);

        $this->postJson('/api/v1/billing/provision', $payload)->assertCreated();
        $this->postJson('/api/v1/billing/provision', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $this->assertSame(1, Server::count(), 'Zdublowany webhook nie może podwoić usługi');
    }

    public function test_istniejacy_klient_nie_dostaje_drugiego_konta(): void
    {
        $this->actingAsBilling();
        $existing = User::factory()->create(['email' => 'nowy@klient.pl']);

        $this->postJson('/api/v1/billing/provision', $this->payload([
            'hostname' => 'usluga3.example.com',
        ]))->assertCreated();

        $this->assertSame(1, User::where('email', 'nowy@klient.pl')->count());
        $this->assertSame($existing->id, Server::firstOrFail()->user_id);
    }

    public function test_zawieszenie_i_odwieszenie_uslugi(): void
    {
        $this->actingAsBilling();
        $this->postJson('/api/v1/billing/provision', $this->payload([
            'hostname' => 'usluga4.example.com',
        ]))->assertCreated();

        $this->postJson('/api/v1/billing/services/INV-2026-001/suspend', [
            'reason' => 'Brak płatności za fakturę FV/2026/09/1.',
        ])->assertStatus(202);

        $server = Server::firstOrFail();
        $this->assertTrue($server->isSuspended());
        $this->assertStringContainsString('FV/2026/09/1', $server->suspension_reason);

        // Powtórzone zawieszenie nie jest błędem — webhooki bywają dublowane.
        $this->postJson('/api/v1/billing/services/INV-2026-001/suspend')
            ->assertOk()
            ->assertJsonPath('idempotent', true);

        $this->postJson('/api/v1/billing/services/INV-2026-001/unsuspend')->assertStatus(202);
        $this->assertFalse($server->fresh()->isSuspended());
    }

    public function test_terminacja_wymaga_potwierdzenia(): void
    {
        $this->actingAsBilling();
        $this->postJson('/api/v1/billing/provision', $this->payload([
            'hostname' => 'usluga5.example.com',
        ]))->assertCreated();

        $this->postJson('/api/v1/billing/services/INV-2026-001/terminate')
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');

        $this->postJson('/api/v1/billing/services/INV-2026-001/terminate', ['confirm' => true])
            ->assertStatus(202);
    }

    public function test_token_bez_uprawnienia_billing_nie_ma_dostepu(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), ['*']);

        // Sanctum::actingAs z '*' daje wszystkie uprawnienia — sprawdzamy token
        // ograniczony do czegoś innego.
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]), ['servers:read']);

        $this->postJson('/api/v1/billing/provision', $this->payload())->assertForbidden();
    }

    public function test_sso_wystawia_jednorazowy_bilet_logowania(): void
    {
        $this->actingAsBilling();
        $user = User::factory()->create(['email' => 'klient@example.com']);

        $response = $this->postJson('/api/v1/billing/sso-token', [
            'customer_email' => 'klient@example.com',
        ])->assertOk();

        $url = $response->json('url');
        $this->assertNotEmpty($url);

        // Sprawdzamy guard sesyjny, nie domyślny — w tym teście domyślnym jest
        // wciąż token Sanctum, którym wystawialiśmy bilet.
        $this->get($url)->assertRedirect(route('panel.dashboard'));
        $this->assertAuthenticatedAs($user, 'web');

        // …drugie użycie już nie loguje, bo bilet został zużyty.
        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_sso_dla_nieznanego_klienta_konczy_sie_404(): void
    {
        $this->actingAsBilling();

        $this->postJson('/api/v1/billing/sso-token', [
            'customer_email' => 'nieznany@example.com',
        ])->assertNotFound();
    }
}
