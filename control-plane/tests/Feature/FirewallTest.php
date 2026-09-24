<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Enums\ServerState;
use App\Models\FirewallRule;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FirewallTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->server = Server::factory()->create([
            'user_id' => $this->owner->id,
            'hypervisor_id' => Hypervisor::factory()->create()->id,
            'agent_uuid' => '11111111-2222-3333-4444-555555555555',
        ]);
        $this->server->markState(ServerState::Running);
    }

    private function addRule(User $user, array $data = [])
    {
        return $this->actingAs($user)->post(route('panel.servers.firewall.store', $this->server), [
            'action' => 'accept', 'direction' => 'in', 'protocol' => 'tcp', 'port_from' => 22, ...$data,
        ]);
    }

    private function networkJobs(): int
    {
        return ServerJob::where('server_id', $this->server->id)->where('action', 'network')->count();
    }

    // --- klient -------------------------------------------------------------

    public function test_klient_dodaje_regule_i_zapora_sie_wlacza(): void
    {
        $this->addRule($this->owner, ['source' => '198.51.100.7'])->assertSessionHasNoErrors();

        $rule = FirewallRule::firstOrFail();
        $this->assertSame('customer', $rule->managed_by);
        $this->assertSame('198.51.100.7', $rule->source);
        $this->assertTrue($this->server->fresh()->firewall_enabled, 'Reguła bez włączonej zapory nie działałaby');
        $this->assertSame(1, $this->networkJobs(), 'Zmiana musi trafić na węzeł');
    }

    public function test_adres_jest_walidowany_i_normalizowany(): void
    {
        $this->addRule($this->owner, ['source' => '10.0.0.0/8; flush ruleset'])->assertSessionHasErrors('source');
        $this->addRule($this->owner, ['source' => '10.1.2.3/8'])->assertSessionHasNoErrors();

        $this->assertSame('10.0.0.0/8', FirewallRule::firstOrFail()->source);
    }

    public function test_porty_tylko_dla_tcp_i_udp(): void
    {
        $this->addRule($this->owner, ['protocol' => 'icmp', 'port_from' => 22])->assertSessionHasNoErrors();

        $this->assertNull(FirewallRule::firstOrFail()->port_from);
    }

    public function test_odwrocony_zakres_portow(): void
    {
        $this->addRule($this->owner, ['port_from' => 2000, 'port_to' => 1000])->assertSessionHasErrors('port_to');
    }

    public function test_szablon_http_https(): void
    {
        $this->actingAs($this->owner)
            ->post(route('panel.servers.firewall.preset', [$this->server, 'web']))
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([80, 443], FirewallRule::pluck('port_from')->all());
    }

    public function test_polityka_domyslna_i_ladunek_agenta(): void
    {
        $this->addRule($this->owner);
        $this->actingAs($this->owner)
            ->post(route('panel.servers.firewall.policy', $this->server), [
                'enabled' => 1, 'inbound' => 'drop', 'outbound' => 'accept',
            ])
            ->assertSessionHasNoErrors();

        $payload = ServerPayload::forNetwork($this->server->fresh());

        $this->assertSame(['enabled' => true, 'inbound' => 'drop', 'outbound' => 'accept'], $payload['policy']);
        $this->assertSame(22, $payload['firewall'][0]['port_from']);
    }

    public function test_wylaczona_regula_nie_trafia_do_agenta(): void
    {
        $this->addRule($this->owner);
        $rule = FirewallRule::firstOrFail();

        $this->actingAs($this->owner)->post(route('panel.servers.firewall.toggle', [$this->server, $rule]));

        $this->assertFalse($rule->fresh()->enabled);
        $this->assertSame([], ServerPayload::forNetwork($this->server->fresh())['firewall']);
    }

    public function test_kolejnosc_regul(): void
    {
        $this->addRule($this->owner, ['port_from' => 22]);
        $this->addRule($this->owner, ['port_from' => 80]);
        $second = FirewallRule::where('port_from', 80)->firstOrFail();

        $this->actingAs($this->owner)->post(route('panel.servers.firewall.move', [$this->server, $second, 'up']));

        $this->assertSame([80, 22], array_column(ServerPayload::forNetwork($this->server->fresh())['firewall'], 'port_from'));
    }

    public function test_obcy_klient_nie_zmieni_zapory(): void
    {
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->addRule($stranger)->assertForbidden();
        $this->assertSame(0, FirewallRule::count());
    }

    // --- administrator ------------------------------------------------------

    public function test_regula_administratora_jest_pierwsza_i_klient_jej_nie_usunie(): void
    {
        $this->addRule($this->owner, ['port_from' => 80]);
        $this->addRule($this->admin, ['action' => 'drop', 'protocol' => 'any', 'source' => '192.0.2.0/24', 'admin_rule' => 1]);
        $adminRule = FirewallRule::where('managed_by', 'admin')->firstOrFail();

        $payload = ServerPayload::forNetwork($this->server->fresh())['firewall'];
        $this->assertSame('drop', $payload[0]['action'], 'Reguły administratora przed regułami klienta');

        $this->actingAs($this->owner)
            ->delete(route('panel.servers.firewall.destroy', [$this->server, $adminRule]))
            ->assertForbidden();
        $this->assertModelExists($adminRule);

        $this->actingAs($this->owner)->get(route('panel.servers.show', $this->server))
            ->assertOk()
            ->assertSee('administrator');
    }

    public function test_klient_nie_moze_ustawic_reguly_administratora(): void
    {
        $this->addRule($this->owner, ['admin_rule' => 1]);

        $this->assertSame('customer', FirewallRule::firstOrFail()->managed_by);
    }

    public function test_blokada_zapory_przez_administratora(): void
    {
        $this->actingAs($this->admin)->post(route('panel.servers.firewall.policy', $this->server), [
            'enabled' => 1, 'inbound' => 'accept', 'outbound' => 'accept', 'locked' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->server->fresh()->firewall_locked);

        $this->addRule($this->owner)->assertForbidden();
        $this->actingAs($this->owner)->post(route('panel.servers.firewall.policy', $this->server), [
            'enabled' => 0, 'inbound' => 'accept', 'outbound' => 'accept',
        ])->assertForbidden();
        $this->assertTrue($this->server->fresh()->firewall_enabled);

        // Administrator dalej może.
        $this->addRule($this->admin)->assertSessionHasNoErrors();
    }

    public function test_klient_nie_zdejmie_blokady(): void
    {
        $this->server->forceFill(['firewall_locked' => false])->save();

        $this->actingAs($this->owner)->post(route('panel.servers.firewall.policy', $this->server), [
            'enabled' => 1, 'inbound' => 'drop', 'outbound' => 'accept', 'locked' => 1,
        ]);

        $this->assertFalse($this->server->fresh()->firewall_locked, 'Blokadę ustawia tylko personel');
    }

    public function test_limit_regul(): void
    {
        FirewallRule::factory()->count(50)->create(['server_id' => $this->server->id]);

        $this->addRule($this->owner)->assertSessionHasErrors('rule');
    }

    // --- API ------------------------------------------------------------------

    public function test_api_polityka_i_lista(): void
    {
        $this->actingAs($this->owner)
            ->putJson("/api/v1/servers/{$this->server->id}/firewall/policy", [
                'enabled' => true, 'inbound' => 'drop', 'outbound' => 'accept',
            ])
            ->assertOk()
            ->assertJsonPath('policy.inbound', 'drop');

        $this->actingAs($this->owner)
            ->postJson("/api/v1/servers/{$this->server->id}/firewall", [
                'action' => 'accept', 'direction' => 'in', 'protocol' => 'tcp', 'port_from' => 443,
            ])
            ->assertCreated();

        $this->actingAs($this->owner)
            ->getJson("/api/v1/servers/{$this->server->id}/firewall")
            ->assertOk()
            ->assertJsonPath('policy.editable', true)
            ->assertJsonPath('data.0.port_from', 443)
            ->assertJsonPath('data.0.managed_by', 'customer');
    }

    public function test_panel_administratora_pokazuje_stan_zapory(): void
    {
        $this->server->forceFill(['firewall_enabled' => true, 'firewall_inbound' => 'drop'])->save();

        $this->actingAs($this->admin)->get(route('panel.admin.servers'))
            ->assertOk()
            ->assertSee('restrykcyjna');
    }
}
