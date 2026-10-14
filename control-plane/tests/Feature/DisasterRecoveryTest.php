<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\DisasterRecovery;
use App\Domain\Provisioning\RemoteBackups;
use App\Enums\ServerState;
use App\Jobs\DrSnapshotJob;
use App\Models\Backup;
use App\Models\BackupPlan;
use App\Models\BackupTarget;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DisasterRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BackupTarget $target;

    private Hypervisor $failed;

    private Hypervisor $replacement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->target = BackupTarget::create(['name' => 'S3', 'type' => 's3', 'config' => ['endpoint' => 'https://s3.example.test', 'bucket' => 'dr', 'access_key' => 'AK', 'secret_key' => 'SK']]);
        $this->failed = Hypervisor::factory()->create(['name' => 'kvm-1', 'virtualization' => 'kvm', 'status' => Hypervisor::STATUS_OFFLINE, 'last_seen_at' => now()->subHour(),
            'cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 1000, 'cpu_cores_used' => 6, 'ram_mb_used' => 8192, 'disk_gb_used' => 120]);
        $this->replacement = Hypervisor::factory()->create(['name' => 'kvm-2', 'virtualization' => 'kvm', 'cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 1000]);
    }

    /** @return array{0: Server, 1: Server, 2: Server, 3: IpPool} */
    private function fleet(): array
    {
        $pool = IpPool::factory()->create(['hypervisor_id' => $this->failed->id]);
        $ip = IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $this->failed->id]);
        $withBackup = Server::factory()->create(['hypervisor_id' => $this->failed->id, 'hostname' => 'a.example.com', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'agent_uuid' => '11111111-1111-1111-1111-111111111111']);
        $ip->forceFill(['server_id' => $withBackup->id, 'is_primary' => true])->save();
        $withBackup->backups()->create(['name' => 'stara', 'type' => Backup::TYPE_REMOTE, 'status' => Backup::STATUS_READY, 'backup_target_id' => $this->target->id, 'remote_key' => 'vm/a-old.qcow2', 'sha256' => str_repeat('a', 64)])
            ->forceFill(['created_at' => now()->subDays(2)])->save();
        $withBackup->backups()->create(['name' => 'nowa', 'type' => Backup::TYPE_REMOTE, 'status' => Backup::STATUS_READY, 'backup_target_id' => $this->target->id, 'remote_key' => 'vm/a-new.qcow2', 'sha256' => str_repeat('b', 64)]);
        $noBackup = Server::factory()->create(['hypervisor_id' => $this->failed->id, 'hostname' => 'b.example.com', 'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 40]);
        $container = Server::factory()->create(['hypervisor_id' => $this->failed->id, 'hostname' => 'c.example.com', 'virtualization' => 'lxc', 'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 40]);

        return [$withBackup, $noBackup, $container, $pool];
    }

    public function test_odtworzenie_na_zastepczym_wezle(): void
    {
        Http::fake(['*/vm/recover' => Http::response(['job_id' => 'rec-1', 'status' => 'queued'], 202)]);
        [$withBackup, $noBackup, $container, $pool] = $this->fleet();

        $this->actingAs($this->admin)->post(route('panel.admin.dr.recover', $this->failed), ['replacement_id' => $this->replacement->id, 'confirm' => 1])
            ->assertSessionHasNoErrors();

        $withBackup->refresh();
        $this->assertSame($this->replacement->id, $withBackup->hypervisor_id);
        $this->assertSame(ServerState::Rebuilding, $withBackup->state);
        $this->assertSame($this->replacement->id, $pool->fresh()->hypervisor_id);
        $this->assertSame($this->replacement->id, IpAddress::where('server_id', $withBackup->id)->value('hypervisor_id'));
        $this->assertSame(Hypervisor::STATUS_MAINTENANCE, $this->failed->fresh()->status);
        $this->assertFalse($this->failed->fresh()->accepts_new_servers);
        $this->assertSame(2, $this->replacement->fresh()->cpu_cores_used);
        $this->assertSame(4096, $this->replacement->fresh()->ram_mb_used);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/vm/recover')
            && $r['server_id'] === $withBackup->id
            && $r['backup']['key'] === 'vm/a-new.qcow2'
            && $r['backup']['sha256'] === str_repeat('b', 64)
            && $r['interfaces'][0]['address'] === IpAddress::where('server_id', $withBackup->id)->value('address'));

        $this->assertSame(ServerState::Error, $noBackup->fresh()->state);
        $this->assertStringContainsString('brak gotowej kopii', $noBackup->fresh()->state_message);
        $this->assertSame($this->failed->id, $noBackup->fresh()->hypervisor_id);
        $this->assertStringContainsString('kontener', $container->fresh()->state_message);

        // Wynik z agenta: nowy UUID domeny, maszyna działa.
        $job = ServerJob::where('action', 'recover')->firstOrFail();
        $this->assertSame('rec-1', $job->agent_job_id);
        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'done', 'result' => ['uuid' => '22222222-2222-2222-2222-222222222222', 'state' => 'running', 'vnc_port' => 5901]]);
        $withBackup->refresh();
        $this->assertSame('22222222-2222-2222-2222-222222222222', $withBackup->agent_uuid);
        $this->assertSame(ServerState::Running, $withBackup->state);
    }

    public function test_nieudane_odtworzenie_i_blokady(): void
    {
        Http::fake(['*/vm/recover' => Http::response(['job_id' => 'rec-2', 'status' => 'queued'], 202)]);
        [$withBackup] = $this->fleet();
        $dr = app(DisasterRecovery::class);

        // Węzeł, który wciąż raportuje, nie jest „awaryjny”.
        $this->failed->forceFill(['status' => Hypervisor::STATUS_ONLINE, 'last_seen_at' => now()])->save();
        $this->expectExceptionObject(new \DomainException(__('Węzeł :name wciąż raportuje stan — przełącz go w tryb serwisowy, zanim odtworzysz maszyny gdzie indziej.', ['name' => 'kvm-1'])));
        try {
            $dr->recover($this->failed, $this->replacement, $this->admin);
        } finally {
            $this->failed->forceFill(['status' => Hypervisor::STATUS_OFFLINE, 'last_seen_at' => now()->subHour()])->save();
            $dr->recover($this->failed, $this->replacement, $this->admin);
            $job = ServerJob::where('action', 'recover')->firstOrFail();
            app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'failed', 'error' => 'Suma kontrolna kopii się nie zgadza']);
            $this->assertSame(ServerState::Error, $withBackup->fresh()->state);
            $this->assertStringContainsString('Suma kontrolna', $withBackup->fresh()->state_message);
        }
    }

    public function test_plan_dr_obejmuje_maszyny_bez_planu(): void
    {
        Queue::fake();
        $plan = BackupPlan::create(['name' => 'DR', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3, 'retention' => 3]);
        $node = Hypervisor::factory()->create(['virtualization' => 'kvm', 'dr_backup_plan_id' => $plan->id]);
        $own = BackupPlan::create(['name' => 'Własny', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3, 'retention' => 3]);
        $a = Server::factory()->create(['hypervisor_id' => $node->id]);
        $b = Server::factory()->create(['hypervisor_id' => $node->id, 'backup_plan_id' => $own->id]);
        Server::factory()->create(['hypervisor_id' => $node->id, 'virtualization' => 'lxc']);
        Server::factory()->create(); // inny węzeł, bez planu

        $this->assertSame($plan->id, $a->effectiveBackupPlan()?->id);
        $this->assertSame($own->id, $b->effectiveBackupPlan()?->id);
        $this->travelTo(now()->setTime(3, 5));
        $this->assertSame(2, app(RemoteBackups::class)->runDue());
    }

    public function test_polityka_i_zrzut_konfiguracji(): void
    {
        $this->replacement->forceFill(['last_seen_at' => now()])->save();
        $this->actingAs($this->admin)->get(route('panel.admin.dr', $this->failed))->assertOk()->assertSee('kvm-2');
        $this->put(route('panel.admin.dr.update', $this->replacement), ['dr_target_id' => $this->target->id, 'dr_snapshot_hours' => 12])->assertSessionHasNoErrors();
        $this->assertSame(12, $this->replacement->fresh()->dr_snapshot_hours);

        Queue::fake();
        Http::fake(['*/system/dr-snapshot' => Http::response(['job_id' => 'snap-1', 'status' => 'queued'], 202),
            '*/jobs/snap-1' => Http::response(['status' => 'done', 'result' => ['key' => 'k', 'sha256' => 'x', 'domains' => 3]])]);
        $this->post(route('panel.admin.dr.snapshot', $this->replacement))->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/system/dr-snapshot') && str_starts_with($r['key'], 'dr/node-'.$this->replacement->id.'/') && $r['target']['type'] === 's3');
        $this->assertSame('snap-1', $this->replacement->fresh()->dr_snapshot_job_id);
        Queue::assertPushed(DrSnapshotJob::class);

        (new DrSnapshotJob($this->replacement->id, 'dr/node-x/1.json'))->handle();
        $node = $this->replacement->fresh();
        $this->assertNull($node->dr_snapshot_job_id);
        $this->assertNotNull($node->dr_last_snapshot_at);
        $this->assertSame('dr/node-x/1.json', $node->dr_last_snapshot_key);
        // Po świeżym zrzucie harmonogram nic nie zleca.
        $this->assertSame(0, app(DisasterRecovery::class)->runDue());

        $this->actingAs(User::factory()->create())->get(route('panel.admin.dr', $this->failed))->assertForbidden();
    }
}
