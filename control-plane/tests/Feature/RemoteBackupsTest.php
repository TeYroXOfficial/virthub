<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\RemoteBackups;
use App\Domain\Provisioning\ServerProvisioner;
use App\Jobs\RunServerActionJob;
use App\Models\Backup;
use App\Models\BackupPlan;
use App\Models\BackupTarget;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RemoteBackupsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BackupTarget $target;

    private BackupPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->target = BackupTarget::create(['name' => 'Wasabi', 'type' => 's3', 'config' => [
            'endpoint' => 'https://s3.example.test', 'bucket' => 'kopie', 'access_key' => 'AK', 'secret_key' => 'SEKRET',
        ]]);
        $this->plan = BackupPlan::create(['name' => 'Codziennie', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3, 'retention' => 2]);
    }

    private function finish(ServerJob $job, array $result = ['size_bytes' => 2097152, 'sha256' => 'ab']): void
    {
        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'done', 'result' => $result + ['state' => 'running']]);
    }

    public function test_cele_i_plany_w_administracji(): void
    {
        $this->actingAs($this->admin)->get(route('panel.admin.backups'))->assertOk()->assertSee('Wasabi')->assertDontSee('SEKRET');
        $this->post(route('panel.admin.backups.targets.store'), ['type' => 'local', 'name' => 'Dysk', 'config' => ['path' => '../tmp']])
            ->assertSessionHasErrors('config.path');
        $this->post(route('panel.admin.backups.targets.store'), ['type' => 'sftp', 'name' => 'Box', 'config' => ['host' => 'box.test', 'username' => 'u', 'password' => 'p', 'path' => '/kopie']])
            ->assertSessionHasNoErrors();
        $this->assertSame('p', BackupTarget::where('name', 'Box')->firstOrFail()->config['password']);

        // Edycja bez sekretu zachowuje stary.
        $this->put(route('panel.admin.backups.targets.update', $this->target), ['name' => 'Wasabi 2', 'config' => [
            'endpoint' => 'https://s3.example.test', 'bucket' => 'kopie', 'access_key' => 'AK2',
        ], 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('SEKRET', $this->target->fresh()->config['secret_key']);
        $this->assertSame('AK2', $this->target->fresh()->config['access_key']);

        $this->delete(route('panel.admin.backups.targets.destroy', $this->target))->assertSessionHasErrors('target');
        $this->actingAs(User::factory()->create())->get(route('panel.admin.backups'))->assertForbidden();
    }

    public function test_reczna_kopia_wysyla_cel_i_klucz(): void
    {
        $server = Server::factory()->create(['backup_plan_id' => $this->plan->id]);
        $this->actingAs($server->user)->post(route('panel.servers.remote-backups', $server))->assertSessionHasNoErrors();
        $job = ServerJob::where('action', 'backup')->firstOrFail();
        $backup = Backup::firstOrFail();
        $this->assertSame('vm'.$server->id.'/'.$backup->name.'.qcow2', $backup->remote_key);

        Http::fake(['*' => Http::response(['job_id' => 'a1', 'status' => 'queued'], 202)]);
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/vm/{$server->agent_uuid}/backups")
            && $r['key'] === $backup->remote_key && $r['target']['type'] === 's3' && $r['target']['config']['secret_key'] === 'SEKRET' && $r['compress'] === true);

        $this->finish($job);
        $backup->refresh();
        $this->assertSame(Backup::STATUS_READY, $backup->status);
        $this->assertSame(2, $backup->size_mb);
        $this->get(route('panel.servers.show', $server))->assertSee($backup->name);
    }

    public function test_harmonogram_i_retencja(): void
    {
        $server = Server::factory()->create(['backup_plan_id' => $this->plan->id]);
        $this->travelTo(now()->setTime(3, 5));
        $m = app(RemoteBackups::class);

        $this->assertSame(1, $m->runDue());
        $this->assertSame(0, $m->runDue(), 'Kopia w trakcie — bez drugiej');
        $this->finish(ServerJob::where('action', 'backup')->latest('id')->firstOrFail());

        // Następnego dnia o 3:05 — kolejna; o 10:00 — nie.
        $this->travel(7)->hours();
        $this->assertSame(0, $m->runDue());
        $this->travelTo(now()->addDay()->setTime(3, 5));
        $this->assertSame(1, $m->runDue());
        $this->finish(ServerJob::where('action', 'backup')->latest('id')->firstOrFail());
        $this->travelTo(now()->addDay()->setTime(3, 5));
        $this->assertSame(1, $m->runDue());

        Http::fake(['*' => Http::response(['job_id' => 'd1', 'status' => 'queued'], 202)]);
        $this->finish(ServerJob::where('action', 'backup')->latest('id')->firstOrFail());
        // Retencja 2 — najstarsza usunięta z celu.
        $this->assertSame(2, Backup::where('status', 'ready')->count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/backups/delete') && count($r['keys']) === 1);
    }

    public function test_przywrocenie(): void
    {
        $server = Server::factory()->create(['backup_plan_id' => $this->plan->id]);
        $backup = Backup::create(['server_id' => $server->id, 'name' => 'b1', 'type' => 'remote', 'status' => 'ready', 'backup_target_id' => $this->target->id, 'remote_key' => 'vm/b1.qcow2', 'sha256' => str_repeat('a', 64)]);

        $this->actingAs($server->user)->post(route('panel.servers.remote-backups.restore', [$server, $backup]))->assertSessionHasNoErrors();
        $job = ServerJob::where('action', 'backup_restore')->firstOrFail();
        Http::fake(['*' => Http::response(['job_id' => 'r1', 'status' => 'queued'], 202)]);
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/backups/restore') && $r['sha256'] === str_repeat('a', 64));

        $other = Server::factory()->create();
        $this->actingAs($other->user)->post(route('panel.servers.remote-backups.restore', [$other, $backup]))->assertNotFound();
    }

    public function test_pakiet_nadaje_plan_nowej_maszynie(): void
    {
        $package = VpsPackage::factory()->create(['backup_plan_id' => $this->plan->id]);
        $this->actingAs($this->admin)->get(route('panel.admin.packages'))->assertOk()->assertSee('Codziennie');
        $node = Hypervisor::factory()->create(['cpu_cores_total' => 16, 'ram_mb_total' => 32768, 'disk_gb_total' => 500]);
        $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
        IpAddress::factory()->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id]);

        $server = app(ServerProvisioner::class)->order($this->admin, $package, OsTemplate::factory()->create(), 'p.example.test');
        $this->assertSame($this->plan->id, $server->backup_plan_id);
    }
}
