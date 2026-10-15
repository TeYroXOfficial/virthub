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

    public function test_etapy_postep_i_adres_migracji(): void
    {
        $this->source->forceFill(['migration_address' => '10.9.0.1'])->save();
        $this->destination->forceFill(['migration_address' => '10.9.0.2'])->save();
        $state = ['status' => 'running', 'stage' => 'download', 'progress' => 50, 'detail' => '512 MB z 1024 MB'];
        $this->fakeAgents($state);
        Http::fake(['src:8080/jobs/ex1' => Http::response(['status' => 'done', 'result' => ['token' => 'TOK', 'sha256' => str_repeat('c', 64), 'size_bytes' => 1234]])]);
        $migrations = app(Migrations::class);
        $migrations->start($this->server, $this->destination, 'cold', $this->admin);
        $job = $this->drive(3);

        $this->assertSame('importing', $job->payload['phase']);
        $this->assertSame(['exporting', 'importing'], array_column($job->payload['steps'], 'phase'));
        $this->assertNotNull($job->payload['steps'][0]['finished_at']);
        $this->assertNull($job->payload['steps'][1]['finished_at']);
        $this->assertSame(['stage' => 'download', 'percent' => 50, 'detail' => '512 MB z 1024 MB'], $job->payload['progress']);
        $this->assertSame(73, Migrations::overall($job)); // 45 + 55 * 50%
        Http::assertSent(fn (Request $r) => $r->url() === 'http://dst:8080/vm/import' && $r['source']['url'] === 'http://10.9.0.1:8080/transfer/TOK');

        $this->actingAs($this->admin)->get(route('panel.servers.show', $this->server))->assertOk()
            ->assertSee('Przenoszenie dysku')->assertSee('Anuluj migrację')->assertSee('data-kind="migrate"', false);
        $this->getJson(route('panel.servers.status', $this->server))->assertOk()
            ->assertJsonPath('job.stage', 'download')->assertJsonPath('job.stage_progress', 73)->assertJsonPath('job.stage_detail', '512 MB z 1024 MB');
        $this->assertSame('10.9.0.2', MigrateServerJob::migrationHost($this->destination->fresh()));
    }

    public function test_anulowanie_w_trakcie_importu_sprzata_cel_i_wraca_na_zrodlo(): void
    {
        $importState = ['status' => 'running', 'progress' => 10];
        Http::fake([
            'dst:8080/jobs/im1' => function () use (&$importState) {
                return Http::response($importState);
            },
            'dst:8080/migration/cleanup/*' => Http::response(['job_id' => 'cl1', 'status' => 'queued'], 202),
        ]);
        $this->fakeAgents();
        $migrations = app(Migrations::class);
        $migrations->start($this->server, $this->destination, 'cold', $this->admin);
        $job = $this->drive(3);
        $this->assertSame('importing', $job->payload['phase']);

        $this->actingAs($this->admin)->post(route('panel.servers.migration', [$this->server, 'cancel']))->assertSessionHasNoErrors();
        $this->assertTrue($job->fresh()->payload['cancel']);
        // Import kończy się po anulowaniu — wynik jest sprzątany.
        $importState = ['status' => 'done', 'result' => ['uuid' => 'x']];
        $job = $this->drive(3);

        $this->assertSame(ServerJob::STATUS_FAILED, $job->status);
        $this->assertTrue($job->payload['cancelled']);
        $server = $this->server->fresh();
        $this->assertSame($this->source->id, $server->hypervisor_id);
        $this->assertSame(ServerState::Running, $server->state);
        $this->assertSame('Migracja anulowana.', $server->state_message);
        $this->assertSame(0, $this->destination->fresh()->cpu_cores_used);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://dst:8080/migration/cleanup/'.$this->server->id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/power') && $r['action'] === 'start');
        $this->assertNull($job->payload['resumable']);
    }

    public function test_anulowanie_migracji_na_zywo_przerywa_libvirt(): void
    {
        Http::fake([
            'dst:8080/vm/prepare-migration' => Http::response(['job_id' => 'pr1', 'status' => 'queued'], 202),
            'dst:8080/jobs/pr1' => Http::response(['status' => 'done', 'result' => ['ready' => true]]),
            'src:8080/vm/'.self::UUID.'/migrate' => Http::response(['job_id' => 'mg1', 'status' => 'queued'], 202),
            'src:8080/jobs/mg1' => Http::sequence()->push(['status' => 'running', 'progress' => 40, 'detail' => '1 GB z 4 GB'])
                ->push(['status' => 'failed', 'error' => 'Migracja na żywo została anulowana']),
            'src:8080/vm/'.self::UUID.'/migrate/abort' => Http::response(['aborted' => true]),
            'dst:8080/migration/cleanup/*' => Http::response(['job_id' => 'cl1', 'status' => 'queued'], 202),
        ]);
        $migrations = app(Migrations::class);
        $job = $migrations->start($this->server, $this->destination, 'live', $this->admin);
        $this->drive(3);
        $this->assertSame('migrating', $job->fresh()->payload['phase']);
        Sanctum::actingAs($this->admin, ['admin']);
        $this->getJson("/api/v1/admin/servers/{$this->server->id}/migration")->assertOk()
            ->assertJsonPath('data.phase', 'migrating')->assertJsonPath('data.percent', 46)->assertJsonPath('data.progress.detail', '1 GB z 4 GB');
        $this->postJson("/api/v1/admin/servers/{$this->server->id}/migration/cancel")->assertOk()->assertJsonPath('data.cancel_requested', true);
        $job = $this->drive(3);

        $this->assertSame(ServerJob::STATUS_FAILED, $job->status);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://src:8080/vm/'.self::UUID.'/migrate/abort');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dst:8080/migration/cleanup/'));
        $this->assertSame($this->source->id, $this->server->fresh()->hypervisor_id);
        $this->assertSame(ServerState::Running, $this->server->fresh()->state);
        $this->postJson("/api/v1/admin/servers/{$this->server->id}/migration/cancel")->assertStatus(409);
    }

    public function test_wznowienie_od_pobierania_i_cofniecie(): void
    {
        $this->server->markState(ServerState::Stopped);
        Http::fake([
            'src:8080/vm/'.self::UUID.'/export' => Http::response(['job_id' => 'ex1', 'status' => 'queued'], 202),
            'src:8080/jobs/ex1' => Http::response(['status' => 'done', 'result' => ['token' => 'TOK', 'sha256' => str_repeat('c', 64), 'size_bytes' => 1234, 'was_running' => false]]),
            'dst:8080/vm/import' => Http::sequence()->push(['job_id' => 'im1', 'status' => 'queued'], 202)->push(['job_id' => 'im2', 'status' => 'queued'], 202),
            'dst:8080/jobs/im1' => Http::response(['status' => 'failed', 'error' => 'Połączenie zerwane']),
            'dst:8080/jobs/im2' => Http::response(['status' => 'done', 'result' => ['uuid' => '33333333-3333-3333-3333-333333333333', 'state' => 'stopped']]),
            'dst:8080/migration/cleanup/*' => Http::response(['job_id' => 'cl1', 'status' => 'queued'], 202),
            'src:8080/transfer/TOK' => Http::response(['revoked' => true]),
            'src:8080/vm/'.self::UUID => Http::response(['job_id' => 'del1', 'status' => 'queued'], 202),
        ]);
        $migrations = app(Migrations::class);
        $migrations->start($this->server, $this->destination, 'cold', $this->admin);
        $failed = $this->drive();
        $this->assertSame(ServerJob::STATUS_FAILED, $failed->status);
        $this->assertSame('importing', $failed->payload['resumable']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/transfer/TOK'));
        $this->actingAs($this->admin)->get(route('panel.servers.show', $this->server))->assertSee('Wznów od pobierania dysku')->assertSee('Połączenie zerwane');

        $this->post(route('panel.servers.migration', [$this->server, 'resume']))->assertSessionHasNoErrors();
        $resumed = ServerJob::where('action', 'migrate')->latest('id')->firstOrFail();
        $this->assertNotSame($failed->id, $resumed->id);
        $this->assertSame('resume-import', $resumed->payload['phase']);
        $this->assertNotNull($failed->fresh()->payload['resumed_at']);
        $done = $this->drive();
        $this->assertSame(ServerJob::STATUS_DONE, $done->status, (string) $done->error);
        $this->assertSame($this->destination->id, $this->server->fresh()->hypervisor_id);
        $this->assertSame('33333333-3333-3333-3333-333333333333', $this->server->fresh()->agent_uuid);
        $this->assertSame(ServerState::Stopped, $this->server->fresh()->state);
        // Wznowiona migracja drugi raz się nie wznowi.
        $this->expectException(\DomainException::class);
        $migrations->revert($failed->fresh(), $this->admin);
    }

    public function test_cofniecie_nieudanej_migracji_i_lista_zgodnych_wezlow(): void
    {
        $this->fakeAgents(['status' => 'failed', 'error' => 'Brak miejsca']);
        Http::fake(['dst:8080/migration/cleanup/*' => Http::response(['job_id' => 'cl1', 'status' => 'queued'], 202)]);
        $migrations = app(Migrations::class);
        $migrations->start($this->server, $this->destination, 'cold', $this->admin);
        $failed = $this->drive();
        $this->actingAs($this->admin)->post(route('panel.servers.migration', [$this->server, 'revert']))->assertSessionHasNoErrors();
        $this->assertNotNull($failed->fresh()->payload['reverted_at']);
        $this->post(route('panel.servers.migration', [$this->server, 'revert']))->assertSessionHasErrors('migrate');

        $full = Hypervisor::factory()->create(['name' => 'pelny', 'virtualization' => 'kvm', 'cpu_cores_total' => 1, 'ram_mb_total' => 1024, 'disk_gb_total' => 10]);
        Sanctum::actingAs($this->admin, ['admin']);
        $targets = collect($this->getJson("/api/v1/admin/servers/{$this->server->id}/migration-targets")->assertOk()->json('data'))->keyBy('name');
        $this->assertTrue($targets['dst']['cold']);
        $this->assertFalse($targets['pelny']['cold']);
        $this->assertNotEmpty($targets['pelny']['reason']);
        $this->assertTrue($targets['dst']['live'], 'Maszyna znów działa na źródle — na żywo też można');
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
