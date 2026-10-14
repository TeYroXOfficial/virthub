<?php

namespace Tests\Feature;

use App\Domain\Agent\ServerPayload;
use App\Domain\Network\PrivateNetworks;
use App\Domain\Provisioning\Migrations;
use App\Jobs\RunServerActionJob;
use App\Models\Hypervisor;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\ServerNic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateNetworksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Hypervisor $node;

    private Server $a;

    private Server $b;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->node = Hypervisor::factory()->create(['virtualization' => 'kvm']);
        $this->a = Server::factory()->create(['user_id' => $this->owner->id, 'hypervisor_id' => $this->node->id, 'agent_uuid' => '11111111-1111-1111-1111-111111111111']);
        $this->b = Server::factory()->create(['user_id' => $this->owner->id, 'hypervisor_id' => $this->node->id, 'agent_uuid' => '22222222-2222-2222-2222-222222222222']);
    }

    public function test_nowa_siec_i_podlaczenie_dwoch_maszyn(): void
    {
        $this->actingAs($this->owner)->post(route('panel.servers.private-networks.attach', $this->a), ['name' => 'backend', 'cidr' => '10.20.0.7/24'])
            ->assertSessionHasNoErrors();
        $network = PrivateNetwork::firstOrFail();
        $this->assertSame('10.20.0.0/24', $network->cidr, 'Adres sieci znormalizowany');
        $this->assertSame($this->node->id, $network->hypervisor_id);

        $this->post(route('panel.servers.private-networks.attach', $this->b), ['network_id' => $network->id, 'address' => '10.20.0.50'])->assertSessionHasNoErrors();
        $this->assertSame(['10.20.0.1', '10.20.0.50'], ServerNic::orderBy('id')->pluck('address')->all());
        $this->assertSame(2, ServerJob::where('action', 'private_nics')->count());
        Queue::assertPushed(RunServerActionJob::class, 2);

        // Zajęty adres i druga karta w tej samej sieci.
        $this->post(route('panel.servers.private-networks.attach', $this->a), ['network_id' => $network->id])->assertSessionHasErrors('private_network');
        $c = Server::factory()->create(['user_id' => $this->owner->id, 'hypervisor_id' => $this->node->id, 'agent_uuid' => '33333333-3333-3333-3333-333333333333']);
        $this->post(route('panel.servers.private-networks.attach', $c), ['network_id' => $network->id, 'address' => '10.20.0.50'])->assertSessionHasErrors('private_network');

        // Ładunek dla agenta: tworzenie i zlecenie kart.
        $payload = ServerPayload::forCreate($this->a->fresh());
        $this->assertSame([['slot' => 1, 'network_id' => $network->id, 'address' => '10.20.0.1', 'prefix' => 24]], $payload['private_nics']);
        $this->assertArrayNotHasKey('private_nics', ServerPayload::forCreate($c));

        Http::fake(['*' => Http::response(['job_id' => 'n1', 'status' => 'queued'], 202)]);
        $job = ServerJob::where('action', 'private_nics')->where('server_id', $this->b->id)->firstOrFail();
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/vm/22222222-2222-2222-2222-222222222222/nics')
            && $r['nics'][0]['address'] === '10.20.0.50' && $r['nics'][0]['slot'] === 1);
        $this->assertSame('n1', $job->fresh()->agent_job_id);

        $this->get(route('panel.servers.show', $this->a))->assertOk()->assertSee('Sieci prywatne')->assertSee('backend')->assertSee('10.20.0.1/24')->assertSee('52:54:01:');
    }

    public function test_izolacja_klientow_i_wezlow(): void
    {
        $service = app(PrivateNetworks::class);
        $network = $service->create($this->owner, $this->node, 'db');
        $this->assertMatchesRegularExpression('#^10\.\d+\.\d+\.0/24$#', $network->cidr);

        $stranger = Server::factory()->create(['hypervisor_id' => $this->node->id, 'agent_uuid' => '44444444-4444-4444-4444-444444444444']);
        $this->actingAs($stranger->user)->post(route('panel.servers.private-networks.attach', $stranger), ['network_id' => $network->id])->assertNotFound();

        $elsewhere = Server::factory()->create(['user_id' => $this->owner->id, 'agent_uuid' => '55555555-5555-5555-5555-555555555555']);
        $this->actingAs($this->owner)->post(route('panel.servers.private-networks.attach', $elsewhere), ['network_id' => $network->id])
            ->assertSessionHasErrors(['private_network' => __('Sieć prywatna działa na jednym węźle — maszyna jest na innym niż sieć :name.', ['name' => 'db'])]);

        $container = Server::factory()->create(['user_id' => $this->owner->id, 'hypervisor_id' => $this->node->id, 'virtualization' => 'lxc', 'agent_uuid' => '66666666-6666-6666-6666-666666666666']);
        $this->post(route('panel.servers.private-networks.attach', $container), ['network_id' => $network->id])->assertSessionHasErrors('private_network');

        foreach (['8.8.8.0/24', '10.0.0.0/8', '192.168.1.0/30', 'zly'] as $cidr) {
            try {
                $service->normaliseCidr($cidr);
                $this->fail("Przyjęto {$cidr}");
            } catch (\DomainException) {
            }
        }
        $this->assertSame('172.16.4.0/22', $service->normaliseCidr('172.16.5.9/22'));
    }

    public function test_odlaczenie_usuniecie_sieci_i_migracja(): void
    {
        $service = app(PrivateNetworks::class);
        $network = $service->create($this->owner, $this->node, 'lan', '192.168.50.0/29');
        $service->attach($this->a, $network);
        $nic = ServerNic::firstOrFail();

        $destination = Hypervisor::factory()->create(['virtualization' => 'kvm', 'cpu_cores_total' => 64, 'ram_mb_total' => 131072, 'disk_gb_total' => 4000]);
        $this->expectExceptionMessageFromCall(fn () => app(Migrations::class)->assertMigratable($this->a->fresh(), $destination, 'cold'), 'sieci prywatnej');

        $this->actingAs($this->owner)->delete(route('panel.servers.private-networks.destroy', [$this->a, $network]))->assertSessionHasErrors('private_network');
        $this->delete(route('panel.servers.nics.detach', [$this->b, $nic]))->assertNotFound();
        $this->delete(route('panel.servers.nics.detach', [$this->a, $nic]))->assertSessionHasNoErrors();
        $this->assertSame(0, ServerNic::count());
        $this->assertSame(2, ServerJob::where('action', 'private_nics')->count());
        $this->delete(route('panel.servers.private-networks.destroy', [$this->a, $network]))->assertSessionHasNoErrors();
        $this->assertSame(0, PrivateNetwork::count());

        // /29: 6 adresów hostów, potem koniec puli.
        $small = $service->create($this->owner, $this->node, 'mala', '192.168.60.0/29');
        for ($i = 0; $i < 6; $i++) {
            $s = Server::factory()->create(['user_id' => $this->owner->id, 'hypervisor_id' => $this->node->id, 'agent_uuid' => fake()->uuid()]);
            $service->attach($s, $small);
        }
        $this->assertSame('192.168.60.6', ServerNic::orderByDesc('id')->value('address'));
        $this->expectException(\DomainException::class);
        $service->attach(Server::factory()->create(['user_id' => $this->owner->id, 'hypervisor_id' => $this->node->id, 'agent_uuid' => fake()->uuid()]), $small);
    }

    public function test_api(): void
    {
        Sanctum::actingAs($this->owner, ['servers:read', 'servers:write']);
        $this->postJson("/api/v1/servers/{$this->a->id}/private-networks", ['name' => 'api', 'cidr' => '10.77.0.0/24', 'address' => '10.77.0.10'])
            ->assertStatus(202)->assertJsonPath('nic.address', '10.77.0.10')->assertJsonPath('nic.slot', 1)->assertJsonPath('nic.network.cidr', '10.77.0.0/24');
        $this->getJson("/api/v1/servers/{$this->a->id}/private-networks")->assertOk()->assertJsonPath('networks.0.servers', 1)->assertJsonPath('limit', 4);
        $nic = ServerNic::firstOrFail();
        $this->postJson("/api/v1/servers/{$this->b->id}/private-networks", ['network_id' => $nic->private_network_id, 'address' => '10.77.0.10'])->assertStatus(409);
        $this->deleteJson("/api/v1/servers/{$this->a->id}/nics/{$nic->id}")->assertStatus(202);
        $this->assertSame(0, ServerNic::count());
    }

    private function expectExceptionMessageFromCall(callable $call, string $fragment): void
    {
        try {
            $call();
            $this->fail('Oczekiwano wyjątku');
        } catch (\DomainException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }
}
