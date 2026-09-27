<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Domain\Network\IpPoolManager;
use App\Domain\Network\PortForwarding;
use App\Domain\Provisioning\IpAllocator;
use App\Jobs\RunServerActionJob;
use App\Models\Hypervisor;
use App\Models\IpPool;
use App\Models\NatPortForward;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Przekierowania portów NAT: usługi stałe wg systemu i porty ustawiane przez klienta. */
class PortForwardingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $node = Hypervisor::factory()->create();
        app(IpPoolManager::class)->create([
            'name' => 'NAT', 'prefix' => 24, 'hypervisor_id' => $node->id, 'type' => 'nat',
            'cidr' => '10.10.0.0/24', 'gateway' => '10.10.0.1',
            'range_from' => '10.10.0.5', 'range_to' => '10.10.0.9',
            'nat_port_start' => 10000, 'nat_ports_per_server' => 10,
        ]);

        $this->owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->server = Server::factory()->create(['user_id' => $this->owner->id, 'hypervisor_id' => $node->id]);
        app(IpAllocator::class)->allocate($this->server, $node, 1, 0, IpPool::TYPE_NAT);
    }

    private function ip()
    {
        return $this->server->ipAddresses()->with('pool')->first();
    }

    /** @return array<int, int> port węzła → port w maszynie */
    private function forwards(): array
    {
        $nat = ServerPayload::interfaces($this->server->fresh())[0]['nat'];

        return collect($nat['forwards'])->mapWithKeys(fn ($f) => [$f['external'] => $f['internal']])->all();
    }

    public function test_linux_ma_ssh_na_pierwszym_porcie_a_reszte_jeden_do_jednego(): void
    {
        // Adres .5 → blok 10050–10059.
        $this->assertSame([
            10050 => 22, 10051 => 10051, 10052 => 10052, 10053 => 10053, 10054 => 10054,
            10055 => 10055, 10056 => 10056, 10057 => 10057, 10058 => 10058, 10059 => 10059,
        ], $this->forwards());
    }

    public function test_windows_ma_rdp_i_ssh_na_stalych_portach(): void
    {
        $this->server->update(['os_template_id' => OsTemplate::factory()->manualInstall()->create()->id]);

        $forwards = $this->forwards();
        $this->assertSame(3389, $forwards[10050]);
        $this->assertSame(22, $forwards[10051]);
        $this->assertSame(10052, $forwards[10052]);
        $this->assertSame(['from' => 10050, 'to' => 10059, 'ssh' => 10051, 'rdp' => 10050], $this->ip()->natPorts());
    }

    public function test_system_odczytany_z_maszyny_decyduje_o_uslugach(): void
    {
        // Szablon linuksowy, ale z ISO zainstalowano Windows.
        $this->server->forceFill(['guest_os_id' => 'mswindows'])->save();

        $this->assertSame(3389, $this->forwards()[10050]);
    }

    public function test_klient_kieruje_port_na_usluge_w_maszynie(): void
    {
        $this->actingAs($this->owner)
            ->post(route('panel.servers.ports.store', $this->server), [
                'ip_address_id' => $this->ip()->id, 'external_port' => 10055, 'internal_port' => 80, 'label' => 'HTTP',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(80, $this->forwards()[10055]);
        $this->assertDatabaseHas('server_jobs', ['server_id' => $this->server->id, 'action' => 'network']);
        Queue::assertPushed(RunServerActionJob::class);

        // Zmiana tego samego portu nadpisuje wpis zamiast dublować.
        $this->actingAs($this->owner)->post(route('panel.servers.ports.store', $this->server), [
            'ip_address_id' => $this->ip()->id, 'external_port' => 10055, 'internal_port' => 443,
        ]);
        $this->assertSame(443, $this->forwards()[10055]);
        $this->assertSame(1, NatPortForward::count());

        $this->actingAs($this->owner)
            ->get(route('panel.servers.show', $this->server))
            ->assertOk()
            ->assertSee('Przekierowania portów')
            ->assertSee('10.10.0.5:443')
            ->assertSee('ssh -p 10050 root@', false)
            ->assertDontSee('{{', false);
    }

    public function test_usuniecie_przywraca_jeden_do_jednego(): void
    {
        $forward = app(PortForwarding::class)->set($this->server, $this->ip(), 10056, 8080, null);

        $this->actingAs($this->owner)
            ->delete(route('panel.servers.ports.destroy', [$this->server, $forward]))
            ->assertRedirect();

        $this->assertSame(10056, $this->forwards()[10056]);
        $this->assertModelMissing($forward);
    }

    public function test_porty_stale_i_spoza_bloku_sa_odrzucane(): void
    {
        foreach ([10050, 10049, 10060, 22] as $port) {
            $this->actingAs($this->owner)
                ->post(route('panel.servers.ports.store', $this->server), [
                    'ip_address_id' => $this->ip()->id, 'external_port' => $port, 'internal_port' => 80,
                ])
                ->assertSessionHasErrors('external_port');
        }

        $this->assertSame(0, NatPortForward::count());
    }

    public function test_cudza_maszyna_i_cudzy_adres_sa_niedostepne(): void
    {
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($stranger)
            ->post(route('panel.servers.ports.store', $this->server), [
                'ip_address_id' => $this->ip()->id, 'external_port' => 10055, 'internal_port' => 80,
            ])
            ->assertForbidden();

        $other = Server::factory()->create(['user_id' => $this->owner->id, 'hypervisor_id' => $this->server->hypervisor_id]);
        $this->actingAs($this->owner)
            ->post(route('panel.servers.ports.store', $other), [
                'ip_address_id' => $this->ip()->id, 'external_port' => 10055, 'internal_port' => 80,
            ])
            ->assertNotFound();
    }

    public function test_zwolnienie_adresu_kasuje_przekierowania(): void
    {
        app(PortForwarding::class)->set($this->server, $this->ip(), 10057, 25565, 'Minecraft');

        app(IpAllocator::class)->releaseAll($this->server);

        $this->assertSame(0, NatPortForward::count());
    }

    public function test_reinstalacja_przelicza_przekierowania(): void
    {
        $job = ServerJob::create([
            'server_id' => $this->server->id, 'action' => 'rebuild', 'status' => ServerJob::STATUS_RUNNING, 'payload' => [],
        ]);

        app(\App\Domain\Provisioning\AgentResultApplier::class)->apply($job, ['status' => 'done', 'result' => []]);

        $this->assertDatabaseHas('server_jobs', ['server_id' => $this->server->id, 'action' => 'network']);
    }

    public function test_polaczenie_idzie_na_publiczny_adres_wezla_a_nie_nazwe_hosta(): void
    {
        $node = $this->server->hypervisor;
        $node->forceFill([
            'hostname' => 'node1',
            'agent_url' => 'https://127.0.0.1:8443',
            'last_health' => ['public_ipv4' => '203.0.113.10'],
        ])->save();

        $this->actingAs($this->owner)->get(route('panel.servers.show', $this->server))
            ->assertSee('ssh -p 10050 root@203.0.113.10', false)
            ->assertSee('203.0.113.10:10050')
            ->assertDontSee('root@node1', false);

        // Ręcznie ustawiony adres węzła ma pierwszeństwo, adres wyjścia puli — jeszcze większe.
        $node->update(['public_address' => 'node1.example.com']);
        $this->assertSame('node1.example.com', $this->ip()->natEndpoint());

        $this->ip()->pool->update(['nat_public_address' => '198.51.100.7']);
        $this->assertSame('198.51.100.7', $this->ip()->natEndpoint());
    }

    public function test_adres_wezla_bez_zgloszenia_agenta(): void
    {
        $node = new Hypervisor(['hostname' => 'node1', 'agent_url' => 'https://node1.example.com:8443']);
        $this->assertSame('node1.example.com', $node->publicAddress(), 'host z adresu agenta');

        $node->last_health = ['public_ipv4' => '10.0.0.5'];
        $node->agent_url = 'https://10.0.0.5:8443';
        $this->assertSame('10.0.0.5', $node->publicAddress(), 'prywatny adres lepszy niż sama nazwa hosta');

        $node->last_health = null;
        $node->agent_url = null;
        $this->assertSame('node1', $node->publicAddress());
    }
}
