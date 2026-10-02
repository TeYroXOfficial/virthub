<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Domain\Network\ForwardResolver;
use App\Domain\Network\IpPoolManager;
use App\Domain\Network\ReverseDns;
use App\Domain\Provisioning\IpAllocator;
use App\Jobs\RunServerActionJob;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** MAC wymagany przez dostawcę dla adresu IP oraz rDNS (panel + PowerDNS + węzeł). */
class MacAndReverseDnsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Hypervisor $node;

    private Server $server;

    private IpAddress $ip;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->node = Hypervisor::factory()->create(['enrolled_at' => now()]);
        app(IpPoolManager::class)->create([
            'name' => 'Pub', 'type' => 'public', 'cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1', 'prefix' => 24,
            'scope' => 'hypervisor', 'hypervisor_id' => $this->node->id, 'fill' => 'range', 'range_from' => '203.0.113.10', 'range_to' => '203.0.113.12',
        ]);
        $this->server = Server::factory()->create(['hypervisor_id' => $this->node->id, 'user_id' => $this->customer->id]);
        $this->ip = app(IpAllocator::class)->allocate($this->server, $this->node)->first();
    }

    private function resolves(array $addresses): void
    {
        $this->app->instance(ForwardResolver::class, new class($addresses) extends ForwardResolver
        {
            public function __construct(private array $list) {}

            public function addresses(string $hostname): array
            {
                return $this->list;
            }
        });
    }

    private function powerDns(): void
    {
        Setting::put([
            'dns.pdns_url' => 'https://ns1.example.net:8081',
            'dns.pdns_key' => Crypt::encryptString('sekret-api'),
            'dns.pdns_server' => 'localhost',
        ]);
        Http::fake([
            '*/api/v1/servers/localhost/zones' => Http::response([
                ['name' => '0.203.in-addr.arpa.'], ['name' => '113.0.203.in-addr.arpa.'], ['name' => 'example.com.'],
            ]),
            '*/api/v1/servers/localhost/zones/*' => Http::response(null, 204),
            '*' => Http::response(['job_id' => 'agent-1', 'status' => 'queued'], 202),
        ]);
    }

    // --- MAC ---------------------------------------------------------------------------

    public function test_admin_ustawia_mac_i_trafia_on_na_wezel(): void
    {
        $this->actingAs($this->admin)->post(route('panel.admin.ip-addresses.update', $this->ip), [
            'action' => 'edit', 'rdns' => '', 'mac_address' => '02-00-00-AA-BB-CC',
        ])->assertSessionHasNoErrors();

        $this->assertSame('02:00:00:aa:bb:cc', $this->ip->fresh()->mac_address);
        $this->assertSame('02:00:00:aa:bb:cc', ServerPayload::mac($this->server));
        $this->assertSame('02:00:00:aa:bb:cc', ServerPayload::forCreate($this->server->load('template'))['mac']);

        $job = ServerJob::where('action', 'mac')->sole();
        Queue::assertPushed(RunServerActionJob::class);
        Http::fake(['*' => Http::response(['job_id' => 'agent-1', 'status' => 'queued'], 202)]);
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), "/vm/{$this->server->agent_uuid}/mac")
            && $r['mac'] === '02:00:00:aa:bb:cc');
    }

    public function test_reinstalacja_wysyla_mac(): void
    {
        $this->ip->update(['mac_address' => '02:00:00:11:22:33']);
        $job = ServerJob::create(['server_id' => $this->server->id, 'hypervisor_id' => $this->node->id, 'action' => 'rebuild',
            'status' => 'queued', 'payload' => ['template' => 'debian-12.qcow2']]);
        Http::fake(['*' => Http::response(['job_id' => 'agent-2', 'status' => 'queued'], 202)]);

        (new RunServerActionJob($job->id))->handle();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/rebuild') && $r['mac'] === '02:00:00:11:22:33');
    }

    public function test_walidacja_mac(): void
    {
        foreach (['01:00:5e:00:00:01', '02:00:00:aa:bb', 'xx:00:00:aa:bb:cc', '00:00:00:00:00:00'] as $bad) {
            $this->actingAs($this->admin)->post(route('panel.admin.ip-addresses.update', $this->ip), ['action' => 'edit', 'mac_address' => $bad])
                ->assertSessionHasErrors('mac_address');
        }

        // Ten sam MAC na adresie innej maszyny zablokowałby sieć.
        $other = Server::factory()->create(['hypervisor_id' => $this->node->id]);
        $otherIp = app(IpAllocator::class)->allocate($other, $this->node)->first();
        $otherIp->update(['mac_address' => '02:00:00:aa:bb:cc']);
        $this->actingAs($this->admin)->post(route('panel.admin.ip-addresses.update', $this->ip), ['action' => 'edit', 'mac_address' => '02:00:00:aa:bb:cc'])
            ->assertSessionHasErrors('mac_address');

        $this->actingAs($this->customer)->post(route('panel.admin.ip-addresses.update', $this->ip), ['action' => 'edit', 'mac_address' => '02:00:00:00:00:01'])
            ->assertForbidden();
    }

    public function test_klient_widzi_mac_swojego_adresu(): void
    {
        $this->ip->update(['mac_address' => '02:00:00:aa:bb:cc']);
        $this->actingAs($this->customer)->get(route('panel.servers.show', $this->server))->assertOk()->assertSee('02:00:00:aa:bb:cc');
    }

    // --- rDNS -------------------------------------------------------------------------------

    public function test_ptr_name(): void
    {
        $this->assertSame('10.113.0.203.in-addr.arpa.', ReverseDns::ptrName('203.0.113.10'));
        $this->assertSame('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa.', ReverseDns::ptrName('2001:db8::1'));
    }

    public function test_klient_ustawia_rdns_tylko_na_nazwe_wskazujaca_na_adres(): void
    {
        $this->resolves(['198.51.100.1']);
        $this->actingAs($this->customer)->put(route('panel.servers.rdns', [$this->server, $this->ip]), ['rdns' => 'mail.example.com'])
            ->assertSessionHasErrors('rdns');
        $this->assertNull($this->ip->fresh()->rdns);

        $this->resolves([$this->ip->address]);
        $this->actingAs($this->customer)->put(route('panel.servers.rdns', [$this->server, $this->ip]), ['rdns' => 'Mail.Example.com.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('mail.example.com', $this->ip->fresh()->rdns);

        $this->actingAs($this->customer)->put(route('panel.servers.rdns', [$this->server, $this->ip]), ['rdns' => 'nie_nazwa'])
            ->assertSessionHasErrors('rdns');

        $this->actingAs($this->customer)->put(route('panel.servers.rdns', [$this->server, $this->ip]), ['rdns' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->ip->fresh()->rdns);

        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($stranger)->put(route('panel.servers.rdns', [$this->server, $this->ip]), ['rdns' => 'x.example.com'])->assertForbidden();
    }

    public function test_rdns_trafia_do_powerdns_w_najdluzszej_strefie(): void
    {
        $this->powerDns();
        $this->resolves([$this->ip->address]);

        $this->actingAs($this->customer)->put(route('panel.servers.rdns', [$this->server, $this->ip]), ['rdns' => 'mail.example.com'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/zones/113.0.203.in-addr.arpa.')
            && $r->header('X-API-Key')[0] === 'sekret-api'
            && $r['rrsets'][0]['name'] === '10.113.0.203.in-addr.arpa.'
            && $r['rrsets'][0]['changetype'] === 'REPLACE'
            && $r['rrsets'][0]['records'][0]['content'] === 'mail.example.com.');

        // Zwolnienie adresu (usunięcie maszyny) kasuje PTR.
        app(IpAllocator::class)->release($this->ip->fresh());
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && $r['rrsets'][0]['changetype'] === 'DELETE');
        $this->assertNull($this->ip->fresh()->rdns);
    }

    public function test_admin_ustawia_rdns_bez_sprawdzania_rekordu_a(): void
    {
        $this->resolves([]);
        $this->actingAs($this->admin)->post(route('panel.admin.ip-addresses.update', $this->ip), ['action' => 'edit', 'rdns' => 'host.example.org'])
            ->assertSessionHasNoErrors();
        $this->assertSame('host.example.org', $this->ip->fresh()->rdns);
    }

    public function test_brak_strefy_odwrotnej_w_powerdns(): void
    {
        Setting::put(['dns.pdns_url' => 'https://ns1.example.net', 'dns.pdns_key' => Crypt::encryptString('k')]);
        Http::fake(['*/zones' => Http::response([['name' => 'example.com.']])]);

        $this->actingAs($this->admin)->post(route('panel.admin.ip-addresses.update', $this->ip), ['action' => 'edit', 'rdns' => 'host.example.org'])
            ->assertSessionHasErrors('rdns');
        $this->assertNull($this->ip->fresh()->rdns, 'bez PTR w DNS nie udajemy, że rDNS działa');
    }

    public function test_ustawienia_powerdns(): void
    {
        $this->actingAs($this->admin)->get(route('panel.admin.network.dns'))->assertOk()->assertSee('PowerDNS');
        $this->actingAs($this->admin)->put(route('panel.admin.network.dns.update'), [
            'pdns_url' => 'https://ns1.example.net:8081', 'pdns_key' => 'tajny', 'pdns_server' => 'localhost', 'ttl' => 600, 'require_forward' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(ReverseDns::configured());
        $this->assertNotSame('tajny', Setting::get('dns.pdns_key'));
        $this->actingAs($this->admin)->get(route('panel.admin.network.dns'))->assertDontSee('tajny');

        Http::fake(['*' => Http::response([['name' => '113.0.203.in-addr.arpa.'], ['name' => 'example.com.']])]);
        $this->actingAs($this->admin)->post(route('panel.admin.network.dns.test'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, '113.0.203.in-addr.arpa.') && ! str_contains($s, 'example.com.'));

        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPPORT]))->get(route('panel.admin.network.dns'))->assertForbidden();
    }
}
