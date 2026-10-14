<?php

namespace Tests\Feature;

use App\Domain\Provisioning\Migrations;
use App\Enums\ServerState;
use App\Jobs\MigrateServerJob;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Hypervisor $source;

    private Hypervisor $destination;

    private Server $server;

    private const UUID = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $group = HypervisorGroup::factory()->create();
        $node = fn (string $name, array $extra = []) => Hypervisor::factory()->create($extra + ['name' => $name, 'virtualization' => 'kvm', 'hypervisor_group_id' => $group->id,
            'agent_url' => "http://{$name}:8080", 'public_address' => "{$name}.example.net", 'cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 1000]);
        $this->source = $node('src', ['cpu_cores_used' => 2, 'ram_mb_used' => 4096, 'disk_gb_used' => 40]);
        $this->destination = $node('dst');
        $pool = IpPool::factory()->forGroup($group)->create();
        $this->server = Server::factory()->create(['hypervisor_id' => $this->source->id, 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'agent_uuid' => self::UUID]);
        IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $this->source->id, 'server_id' => $this->server->id, 'is_primary' => true]);
    }

    private function drive(int $max = 10): ServerJob
    {
        $job = ServerJob::where('action', 'migrate')->latest('id')->firstOrFail();
        for ($i = 0; $i < $max && ! $job->fresh()->isFinished(); $i++) {
            app()->call([new MigrateServerJob($job->id), 'handle']);
        }

        return $job->fresh();
    }

    private function fakeAgents(array $importState = ['status' => 'done', 'result' => ['uuid' => '22222222-2222-2222-2222-222222222222', 'state' => 'running', 'vnc_port' => 5905]]): void
    {
        Http::fake([
            'src:8080/vm/'.self::UUID.'/export' => Http::response(['job_id' => 'ex1', 'status' => 'queued'], 202),
            'src:8080/jobs/ex1' => Http::response(['status' => 'done', 'result' => ['token' => 'TOK', 'sha256' => str_repeat('c', 64), 'size_bytes' => 1234, 'was_running' => true]]),
            'dst:8080/vm/import' => Http::response(['job_id' => 'im1', 'status' => 'queued'], 202),
            'dst:8080/jobs/im1' => Http::response($importState),
            'src:8080/transfer/TOK' => Http::response(['revoked' => true]),
            'src:8080/vm/'.self::UUID.'/power' => Http::response(['job_id' => 'p1', 'status' => 'queued'], 202),
            'src:8080/vm/'.self::UUID => Http::response(['job_id' => 'del1', 'status' => 'queued'], 202),
        ]);
    }

    public function test_migracja_na_zimno(): void
    {
        $this->fakeAgents();
        $this->actingAs($this->admin)->post(route('panel.servers.migrate', $this->server), ['hypervisor_id' => $this->destination->id, 'mode' => 'cold'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ServerState::Migrating, $this->server->fresh()->state);
        $this->assertSame(2, $this->destination->fresh()->cpu_cores_used, 'Zasoby celu rezerwowane od razu');
        Queue::assertPushed(MigrateServerJob::class);

        $job = $this->drive();
        $this->assertSame(ServerJob::STATUS_DONE, $job->status, (string) $job->error);

        $server = $this->server->fresh();
        $this->assertSame($this->destination->id, $server->hypervisor_id);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $server->agent_uuid);
        $this->assertSame(ServerState::Running, $server->state);
        $this->assertSame(0, $this->source->fresh()->cpu_cores_used);
        $this->assertSame(4096, $this->destination->fresh()->ram_mb_used);

        Http::assertSent(fn (Request $r) => $r->url() === 'http://dst:8080/vm/import'
            && $r['source']['url'] === 'http://src:8080/transfer/TOK' && $r['source']['sha256'] === str_repeat('c', 64)
            && $r['source']['start'] === true && $r['server_id'] === $this->server->id && ! isset($r['root_password'])
            && $r['interfaces'][0]['address'] === IpAddress::where('server_id', $this->server->id)->value('address'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'http://src:8080/transfer/TOK');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'http://src:8080/vm/'.self::UUID);
    }

    public function test_nieudany_import_zostawia_maszyne_na_zrodle(): void
    {
        $this->fakeAgents(['status' => 'failed', 'error' => 'Suma kontrolna przeniesionego dysku się nie zgadza']);
        app(Migrations::class)->start($this->server, $this->destination, 'cold', $this->admin);
        $job = $this->drive();

        $this->assertSame(ServerJob::STATUS_FAILED, $job->status);
        $server = $this->server->fresh();
        $this->assertSame($this->source->id, $server->hypervisor_id);
        $this->assertSame(self::UUID, $server->agent_uuid);
        $this->assertSame(ServerState::Running, $server->state);
        $this->assertStringContainsString('Suma kontrolna', $server->state_message);
        $this->assertSame(0, $this->destination->fresh()->cpu_cores_used);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/power') && $r['action'] === 'start');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/transfer/TOK'));
    }

    public function test_migracja_na_zywo(): void
    {
        Http::fake([
            'dst:8080/vm/prepare-migration' => Http::response(['job_id' => 'pr1', 'status' => 'queued'], 202),
            'dst:8080/jobs/pr1' => Http::response(['status' => 'done', 'result' => ['ready' => true]]),
            'src:8080/vm/'.self::UUID.'/migrate' => Http::response(['job_id' => 'mg1', 'status' => 'queued'], 202),
            'src:8080/jobs/mg1' => Http::response(['status' => 'done', 'result' => ['uuid' => self::UUID, 'migrated' => true]]),
        ]);
        Sanctum::actingAs($this->admin, ['admin']);
        $this->postJson("/api/v1/admin/servers/{$this->server->id}/migrate", ['hypervisor_id' => $this->destination->id, 'mode' => 'live'])->assertStatus(202);
        $job = $this->drive();

        $this->assertSame(ServerJob::STATUS_DONE, $job->status, (string) $job->error);
        $this->assertSame($this->destination->id, $this->server->fresh()->hypervisor_id);
        $this->assertSame(self::UUID, $this->server->fresh()->agent_uuid);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/migrate') && $r['host'] === 'dst.example.net');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/vm/prepare-migration') && $r['disk_gb'] === 40);
    }

    public function test_warunki_migracji(): void
    {
        $m = app(Migrations::class);
        $check = function (callable $prepare, string $expected) use ($m) {
            $prepare();
            try {
                $m->assertMigratable($this->server->fresh(), $this->destination->fresh(), 'cold');
                $this->fail('Oczekiwano odmowy: '.$expected);
            } catch (\DomainException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        };

        $own = IpPool::factory()->create(['hypervisor_id' => $this->source->id]);
        $check(fn () => IpAddress::factory()->create(['ip_pool_id' => $own->id, 'hypervisor_id' => $this->source->id, 'server_id' => $this->server->id]), 'puli przypisanej tylko do węzła źródłowego');
        IpAddress::where('ip_pool_id', $own->id)->delete();

        $check(fn () => $this->destination->forceFill(['cpu_cores_used' => 31])->save(), 'nie ma wolnych zasobów');
        $this->destination->forceFill(['cpu_cores_used' => 0])->save();

        $check(fn () => $this->server->forceFill(['state' => ServerState::Resizing])->save(), 'trwa operacja');
        $this->server->forceFill(['state' => ServerState::Stopped])->save();

        $this->expectException(\DomainException::class);
        $m->assertMigratable($this->server->fresh(), $this->destination, 'live');
    }

    public function test_klient_nie_migruje(): void
    {
        $customer = User::factory()->create();
        $this->server->forceFill(['user_id' => $customer->id])->save();
        $this->actingAs($customer)->post(route('panel.servers.migrate', $this->server), ['hypervisor_id' => $this->destination->id, 'mode' => 'cold'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('panel.servers.show', $this->server))->assertOk()->assertSee('Migracja na inny węzeł')->assertSee('dst');
    }
}
