<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Models\NetworkFilterProfile;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NetworkFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_bez_profili_agent_dostaje_dawny_ladunek(): void
    {
        $server = Server::factory()->create();
        $this->assertArrayNotHasKey('filter', ServerPayload::forNetwork($server));
        $this->assertArrayNotHasKey('filter', ServerPayload::forCreate($server));
    }

    public function test_profil_domyslny_i_per_maszyna(): void
    {
        $server = Server::factory()->create();
        $this->actingAs($this->admin)->get(route('panel.admin.network.filters'))->assertOk();

        $this->post(route('panel.admin.network.filters.store'), [
            'name' => 'Standard', 'anti_spoofing' => '1', 'block_router_advertisements' => '1', 'block_dhcp_server' => '1', 'is_default' => '1',
            'rules' => '[{"action": "drop", "direction": "out", "protocol": "tcp", "port_from": 25}]',
        ])->assertSessionHasNoErrors();
        $standard = NetworkFilterProfile::firstOrFail();
        $this->assertTrue($standard->is_default);
        // Nowy domyślny trafia od razu na istniejące maszyny bez profilu.
        $this->assertSame(1, ServerJob::query()->where('server_id', $server->id)->where('action', 'network')->count());

        $filter = ServerPayload::forNetwork($server->fresh())['filter'];
        $this->assertTrue($filter['block_dhcp_server']);
        $this->assertSame([['action' => 'drop', 'direction' => 'out', 'protocol' => 'tcp', 'port_from' => 25, 'port_to' => null, 'source' => null]], $filter['rules']);

        $this->post(route('panel.admin.network.filters.store'), ['name' => 'Bez filtrowania'])->assertSessionHasNoErrors();
        $open = NetworkFilterProfile::where('name', 'Bez filtrowania')->firstOrFail();
        $this->assertFalse($open->anti_spoofing);
        $this->assertTrue($standard->fresh()->is_default);

        $this->get(route('panel.servers.show', $server))->assertOk()->assertSee('Profil filtrowania sieci');
        $this->post(route('panel.servers.network-filter', $server), ['network_filter_profile_id' => $open->id])->assertRedirect();
        $this->assertFalse(ServerPayload::forNetwork($server->fresh())['filter']['anti_spoofing']);
        $this->assertSame(2, ServerJob::query()->where('server_id', $server->id)->where('action', 'network')->count());
    }

    public function test_walidacja_regul_json(): void
    {
        $this->actingAs($this->admin);
        foreach ([
            '{"action": "drop"}',
            '[{"action": "reject"}]',
            '[{"protocol": "icmp", "port_from": 22}]',
            '[{"protocol": "tcp", "port_from": 70000}]',
            '[{"protocol": "tcp", "port_from": 100, "port_to": 50}]',
            '[{"source": "10.0.0.0/8; flush ruleset"}]',
            'nie json',
        ] as $rules) {
            $this->post(route('panel.admin.network.filters.store'), ['name' => 'X', 'rules' => $rules])->assertSessionHasErrors('rules');
        }
        $this->assertSame(0, NetworkFilterProfile::count());
    }

    public function test_usuniecie_profilu_przywraca_domyslny(): void
    {
        $profile = NetworkFilterProfile::create(['name' => 'Specjalny', 'anti_spoofing' => false]);
        $server = Server::factory()->create(['network_filter_profile_id' => $profile->id]);

        $this->actingAs($this->admin)->delete(route('panel.admin.network.filters.destroy', $profile))->assertRedirect();
        $this->assertNull($server->fresh()->network_filter_profile_id);
        $this->assertSame(1, ServerJob::query()->where('server_id', $server->id)->where('action', 'network')->count());
    }

    public function test_klient_nie_zmieni_profilu(): void
    {
        $profile = NetworkFilterProfile::create(['name' => 'Bez filtrowania', 'anti_spoofing' => false]);
        $server = Server::factory()->create();

        $this->actingAs($server->user)->post(route('panel.servers.network-filter', $server), ['network_filter_profile_id' => $profile->id])->assertForbidden();
        $this->actingAs($server->user)->get(route('panel.admin.network.filters'))->assertForbidden();
        $this->assertNull($server->fresh()->network_filter_profile_id);
    }
}
