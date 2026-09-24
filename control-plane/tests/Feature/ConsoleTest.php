<?php

namespace Tests\Feature;

use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Konsola: bilet → strona z jednorazową sesją → wymiana sesji przez przekaźnik.
 */
class ConsoleTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sekret-przekaznika-testowy';

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        config(['virthub.console_secret' => self::SECRET]);

        $this->owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->server = Server::factory()->create([
            'user_id' => $this->owner->id,
            'hypervisor_id' => Hypervisor::factory()->create([
                'agent_url' => 'https://203.0.113.9:8443',
                'agent_tls_cert' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n",
            ])->id,
            'agent_uuid' => '11111111-2222-3333-4444-555555555555',
            'vnc_password' => 'vncHaslo1',
            'virtualization' => 'kvm',
        ]);
        $this->server->markState(ServerState::Running);
    }

    /** @return array{0: string, 1: string} strona konsoli i identyfikator sesji */
    private function openConsole(?User $user = null): array
    {
        $ticketUrl = $this->actingAs($user ?? $this->owner)
            ->post(route('panel.servers.console', $this->server))
            ->assertRedirect()
            ->headers->get('Location');

        $html = $this->actingAs($user ?? $this->owner)->get($ticketUrl)->assertOk()->getContent();
        preg_match('#/console-ws/([A-Za-z0-9]+)#', $html, $match);

        return [$html, $match[1] ?? ''];
    }

    public function test_strona_konsoli_kvm_laduje_novnc_bez_adresu_wezla(): void
    {
        [$html, $session] = $this->openConsole();

        $this->assertNotSame('', $session);
        // @js zapisuje ukośniki jako \/ — sprawdzamy obie postacie.
        $this->assertStringContainsString('novnc', $html);
        $this->assertMatchesRegularExpression('#core\\\\?/rfb\.js#', $html);
        $this->assertStringNotContainsString('203.0.113.9', $html, 'Adres węzła nie może trafić do przeglądarki');
        $this->assertStringNotContainsString($this->server->hypervisor->agent_token, $html);
    }

    public function test_kontener_dostaje_terminal_xterm(): void
    {
        $this->server->forceFill(['virtualization' => 'lxc'])->save();

        [$html] = $this->openConsole();

        $this->assertStringContainsString('vendor/xterm/xterm.js', $html);
        $this->assertStringNotContainsString('rfb.js', $html);
    }

    public function test_przekaznik_wymienia_sesje_raz(): void
    {
        [, $session] = $this->openConsole();

        $response = $this->postJson("/api/internal/console/{$session}", [], ['X-Console-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('url', 'wss://203.0.113.9:8443/vm/11111111-2222-3333-4444-555555555555/console')
            ->assertJsonPath('kind', 'vnc')
            ->assertJsonPath('ca_pem', $this->server->hypervisor->agent_tls_cert);

        // Podpis ma być tym samym HMAC-em, którym agent weryfikuje każde żądanie.
        $timestamp = $response->json('headers.X-VH-Timestamp');
        $expected = hash_hmac('sha256', implode("\n", [
            $timestamp, 'GET', '/vm/11111111-2222-3333-4444-555555555555/console', hash('sha256', ''),
        ]), $this->server->hypervisor->agent_token);
        $this->assertSame($expected, $response->json('headers.X-VH-Signature'));

        $this->postJson("/api/internal/console/{$session}", [], ['X-Console-Secret' => self::SECRET])
            ->assertStatus(410);
    }

    public function test_bez_sekretu_przekaznika_nic_nie_wyciekaje(): void
    {
        [, $session] = $this->openConsole();

        $this->postJson("/api/internal/console/{$session}", [], ['X-Console-Secret' => 'zly'])->assertForbidden();
        $this->postJson("/api/internal/console/{$session}")->assertForbidden();
    }

    public function test_obcy_klient_nie_otworzy_konsoli(): void
    {
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($stranger)
            ->post(route('panel.servers.console', $this->server))
            ->assertForbidden();
    }

    public function test_bilet_jest_jednorazowy(): void
    {
        $ticketUrl = $this->actingAs($this->owner)
            ->post(route('panel.servers.console', $this->server))
            ->headers->get('Location');

        $this->actingAs($this->owner)->get($ticketUrl)->assertOk();
        $this->actingAs($this->owner)->get($ticketUrl)->assertStatus(410);
    }

    public function test_zatrzymana_maszyna_nie_ma_konsoli(): void
    {
        $this->server->markState(ServerState::Stopped);

        $this->actingAs($this->owner)
            ->post(route('panel.servers.console', $this->server))
            ->assertSessionHasErrors('console');
    }

    public function test_bez_przekaznika_strona_mowi_to_wprost(): void
    {
        config(['virthub.console_secret' => null]);

        [$html, $session] = $this->openConsole();

        $this->assertSame('', $session);
        $this->assertStringContainsString('VIRTHUB_CONSOLE_SECRET', $html);
    }
}
