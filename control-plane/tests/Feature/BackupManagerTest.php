<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\RemoteBackups;
use App\Jobs\RunServerActionJob;
use App\Models\Backup;
use App\Models\BackupPlan;
use App\Models\BackupTarget;
use App\Models\Datastore;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BackupTarget $target;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->target = BackupTarget::create(['name' => 'L', 'type' => 'local', 'config' => ['path' => '/srv/k']]);
        $this->server = Server::factory()->create(['disk_gb' => 50]);
    }

    private function backup(BackupPlan $plan, array $result = []): Backup
    {
        $job = app(RemoteBackups::class)->create($this->server->fresh(), $this->target, true, null, $plan);
        $backup = Backup::findOrFail($job->payload['backup_id']);
        $differential = (bool) $job->payload['differential'];
        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'done', 'result' => $result + [
            'state' => 'running', 'size_bytes' => 1048576, 'sha256' => str_repeat('a', 64), 'fs_frozen' => true, 'encrypted' => false,
            'kind' => $backup->kind, 'checkpoint' => $differential ? null : $backup->checkpoint, 'differential' => $differential,
        ]]);
        $this->travel(1)->hours();

        return $backup->fresh();
    }

    public function test_cele_ftp_rclone_pbs_i_szyfrowanie(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('panel.admin.backups.targets.store'), ['type' => 'ftp', 'name' => 'FTP', 'config' => ['host' => 'ftp.test', 'username' => 'u', 'password' => 'p', 'path' => '/k', 'tls' => '1'], 'encrypt' => 1])
            ->assertSessionHasNoErrors();
        $ftp = BackupTarget::where('name', 'FTP')->firstOrFail();
        $this->assertTrue($ftp->config['tls']);
        $this->assertTrue($ftp->isEncrypted());
        $this->assertSame(32, strlen(base64_decode($ftp->encryption_key)));
        $this->assertSame($ftp->encryption_key, $ftp->toAgentPayload()['encryption_key']);

        $this->post(route('panel.admin.backups.targets.store'), ['type' => 'rclone', 'name' => 'B2', 'config' => ['backend' => 'b2', 'path' => 'kopie', 'params_text' => "account = 0012\nkey = SEKRET\n# komentarz"]])
            ->assertSessionHasNoErrors();
        $this->assertSame(['account' => '0012', 'key' => 'SEKRET'], BackupTarget::where('name', 'B2')->firstOrFail()->config['params']);
        $this->post(route('panel.admin.backups.targets.store'), ['type' => 'rclone', 'name' => 'Zły', 'config' => ['backend' => 'b2', 'params_text' => 'to nie jest para']])
            ->assertSessionHasErrors('config.params_text');

        $this->post(route('panel.admin.backups.targets.store'), ['type' => 'pbs', 'name' => 'PBS', 'config' => [
            'host' => 'pbs.test', 'datastore' => 'ds1', 'username' => 'backup@pbs!vh', 'password' => 'x', 'fingerprint' => implode(':', array_fill(0, 32, 'ab')),
        ]])->assertSessionHasNoErrors();
        $this->assertStringContainsString('backup@pbs!vh@pbs.test:ds1', BackupTarget::where('name', 'PBS')->firstOrFail()->describe());
        $this->get(route('panel.admin.backups', ['type' => 'pbs']))->assertOk()->assertSee('Odcisk certyfikatu PBS')->assertDontSee('SEKRET');
    }

    public function test_kopie_roznicowe_od_ostatniej_pelnej(): void
    {
        $plan = BackupPlan::create(['name' => 'D', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3, 'retention' => 10, 'mode' => 'differential', 'full_every' => 3]);
        $full = $this->backup($plan);
        $this->assertSame(Backup::KIND_FULL, $full->kind);
        $d1 = $this->backup($plan);
        $d2 = $this->backup($plan);
        $this->assertSame($full->id, $d1->parent_id);
        $this->assertSame($full->id, $d2->parent_id, 'Różnicowa zawsze od pełnej, nie od poprzedniej');
        $this->assertNotNull($full->fresh()->checkpoint, 'Checkpoint pełnej zostaje');
        $this->assertTrue($d2->fs_frozen);
        $this->assertSame([$full->id], array_map(fn ($b) => $b->id, $d2->ancestors()), 'Przywrócenie: pełna + jedna różnicowa');

        Http::fake(['*' => Http::response(['job_id' => 'b1', 'status' => 'queued'], 202)]);
        $job = app(RemoteBackups::class)->create($this->server->fresh(), $this->target, true, null, $plan);
        $this->assertSame(Backup::KIND_FULL, Backup::find($job->payload['backup_id'])->kind, 'Pełna co 3 kopie');
        // Ponowne wysłanie zlecenia kopii różnicowej d2 — sprawdza, co trafia do agenta.
        $done = ServerJob::where('action', 'backup')->whereJsonContains('payload->backup_id', $d2->id)->firstOrFail();
        $retry = ServerJob::create(['server_id' => $this->server->id, 'hypervisor_id' => $this->server->hypervisor_id, 'action' => 'backup',
            'status' => ServerJob::STATUS_QUEUED, 'payload' => $done->payload]);
        (new RunServerActionJob($retry->id))->handle();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/backups') && $r['differential'] === true && $r['incremental_from'] === $full->checkpoint);
    }

    public function test_retencja_gfs_i_dni_tygodnia(): void
    {
        $plan = BackupPlan::create(['name' => 'G', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3, 'retention' => 2, 'keep_daily' => 3]);
        $this->server->forceFill(['backup_plan_id' => $plan->id])->save();
        $this->travelTo('2026-10-01 03:00:00');
        $ids = [];
        for ($day = 0; $day < 5; $day++) {
            $ids[] = $this->backup($plan)->id;
            $this->travel(23)->hours();
        }
        // 2 najnowsze + najnowsza z każdego z 3 ostatnich dni = 3 kopie (nakładają się).
        $this->assertEqualsCanonicalizing(array_slice($ids, -3), Backup::query()->pluck('id')->all());

        $weekdays = BackupPlan::create(['name' => 'W', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3, 'retention' => 2, 'days' => [1, 2, 3, 4, 5]]);
        $other = Server::factory()->create(['backup_plan_id' => $weekdays->id]);
        $this->travelTo('2026-10-18 03:05:00'); // niedziela
        app(RemoteBackups::class)->runDue();
        $this->assertSame(0, Backup::where('server_id', $other->id)->count());
        $this->travelTo('2026-10-19 03:05:00'); // poniedziałek
        app(RemoteBackups::class)->runDue();
        $this->assertSame(1, Backup::where('server_id', $other->id)->count());

        $this->actingAs($this->admin)->post(route('panel.admin.backups.plans.store'), ['name' => 'P', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3,
            'retention' => 7, 'mode' => 'differential', 'keep_weekly' => 4, 'days' => [1, 3, 5], 'is_active' => 1])->assertSessionHasNoErrors();
        $p = BackupPlan::where('name', 'P')->firstOrFail();
        $this->assertSame([1, 3, 5], $p->days);
        $this->assertSame('differential', $p->backupMode());
        $this->assertTrue($p->incremental);
    }

    public function test_przywrocenie_na_inna_maszyne_i_ceph(): void
    {
        $plan = BackupPlan::create(['name' => 'F', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3, 'retention' => 5]);
        $backup = $this->backup($plan);
        $small = Server::factory()->create(['disk_gb' => 20]);
        $big = Server::factory()->create(['disk_gb' => 80]);

        $this->actingAs($this->admin)->post(route('panel.admin.backups.restore-onto', $backup), ['server_id' => $small->id])->assertSessionHasErrors('backup');
        $this->post(route('panel.admin.backups.restore-onto', $backup), ['server_id' => $big->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('server_jobs', ['server_id' => $big->id, 'action' => 'backup_restore']);
        $this->actingAs($big->user)->post(route('panel.admin.backups.restore-onto', $backup), ['server_id' => $big->id])->assertForbidden();

        // Dysk w Cephie: kopia pełna mimo planu przyrostowego.
        $ceph = Datastore::create(['name' => 'C', 'type' => 'ceph', 'config' => ['pool' => 'vms', 'monitors' => ['10.0.0.1'], 'user' => 'admin', 'key' => 'AQBzZWNyZXRrZXlrZXlrZXlrZXlrZXk9PQ==']]);
        $onCeph = Server::factory()->create(['datastore_id' => $ceph->id]);
        $incremental = BackupPlan::create(['name' => 'I', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3, 'retention' => 5, 'mode' => 'incremental']);
        $job = app(RemoteBackups::class)->create($onCeph, $this->target, true, null, $incremental);
        $this->assertSame(Backup::KIND_FULL, Backup::find($job->payload['backup_id'])->kind);
        $this->assertNull(Backup::find($job->payload['backup_id'])->checkpoint);
    }
}
