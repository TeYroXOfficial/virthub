<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\DisasterRecovery;
use App\Domain\Provisioning\RemoteBackups;
use App\Domain\Provisioning\ServerProvisioner;
use App\Jobs\RunServerActionJob;
use App\Models\Backup;
use App\Models\BackupPlan;
use App\Models\BackupTarget;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncrementalBackupsTest extends TestCase
{
    use RefreshDatabase;

    private BackupTarget $target;

    private BackupPlan $plan;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->target = BackupTarget::create(['name' => 'S3', 'type' => 's3', 'config' => ['endpoint' => 'https://s3.example.test', 'bucket' => 'b', 'access_key' => 'AK', 'secret_key' => 'SK']]);
        $this->plan = BackupPlan::create(['name' => 'Przyrostowy', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3,
            'retention' => 2, 'incremental' => true, 'full_every' => 3]);
        $this->server = Server::factory()->create(['backup_plan_id' => $this->plan->id]);
    }

    /** Kopia przez planową ścieżkę + wynik agenta. */
    private function backup(string $kind = 'auto', ?string $sha = null): Backup
    {
        $job = app(RemoteBackups::class)->create($this->server->fresh(), $this->target, true, null, $this->plan);
        $backup = Backup::findOrFail($job->payload['backup_id']);
        $resultKind = $kind === 'auto' ? $backup->kind : $kind;
        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'done', 'result' => [
            'state' => 'running', 'size_bytes' => 1048576, 'sha256' => $sha ?? str_repeat(dechex($backup->id % 16), 64),
            'kind' => $resultKind, 'checkpoint' => $backup->checkpoint, 'parent' => $backup->parent?->checkpoint,
        ]]);
        $this->travel(1)->hours();

        return $backup->fresh();
    }

    public function test_lancuch_pelna_i_przyrostowe(): void
    {
        $full = $this->backup();
        $this->assertSame(Backup::KIND_FULL, $full->kind);
        $this->assertSame('vh-'.$full->id, $full->checkpoint);

        $i1 = $this->backup();
        $this->assertSame(Backup::KIND_INCREMENTAL, $i1->kind);
        $this->assertSame($full->id, $i1->parent_id);
        $this->assertNull($full->fresh()->checkpoint, 'Starszy checkpoint unieważniony');

        $i2 = $this->backup();
        $this->assertSame($i1->id, $i2->parent_id);
        $this->assertSame([$full->id, $i1->id], array_map(fn ($b) => $b->id, $i2->ancestors()));

        // Pełna co 3 kopie: czwarta zaczyna nowy łańcuch.
        $full2 = $this->backup();
        $this->assertSame(Backup::KIND_FULL, $full2->kind);
        $this->assertNull($full2->parent_id);

        // Retencja 2: zostają full2 i i2 — a i2 potrzebuje full i i1.
        $ids = Backup::query()->pluck('id')->all();
        sort($ids);
        $this->assertSame([$full->id, $i1->id, $i2->id, $full2->id], $ids);

        $i3 = $this->backup();
        $this->assertSame($full2->id, $i3->parent_id);
        // Teraz najnowsze to i3 i full2 — stary łańcuch znika w całości.
        $this->assertEqualsCanonicalizing([$full2->id, $i3->id], Backup::query()->pluck('id')->all());
    }

    public function test_przywrocenie_wysyla_lancuch_i_wynik_pelnej_przerywa_lancuch(): void
    {
        $full = $this->backup();
        $i1 = $this->backup();
        Http::fake(['*' => Http::response(['job_id' => 'r1', 'status' => 'queued'], 202)]);

        $job = app(RemoteBackups::class)->restore($this->server->fresh(), $i1);
        (new RunServerActionJob($job->id))->handle(app(ServerProvisioner::class));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/backups/restore') && $r['key'] === $i1->remote_key
            && $r['chain'] === [['key' => $full->remote_key, 'sha256' => $full->sha256]]);

        // Agent nie znalazł checkpointu rodzica i zrobił pełną — panel to zapisuje.
        $fallback = $this->backup('full');
        $this->assertSame(Backup::KIND_FULL, $fallback->kind);
        $this->assertNull($fallback->parent_id);

        // Kopia planowa wysyła checkpoint i rodzica.
        Http::fake(['*' => Http::response(['job_id' => 'b1', 'status' => 'queued'], 202)]);
        $job = app(RemoteBackups::class)->create($this->server->fresh(), $this->target, true, null, $this->plan);
        (new RunServerActionJob($job->id))->handle(app(ServerProvisioner::class));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/backups') && $r['checkpoint'] === 'vh-'.$job->payload['backup_id']
            && $r['incremental_from'] === 'vh-'.$fallback->id);
    }

    public function test_usuniecie_pelnej_usuwa_zalezne_i_dr_wybiera_kompletny_lancuch(): void
    {
        Http::fake(['*' => Http::response(['job_id' => 'd1', 'status' => 'queued'], 202)]);
        $full = $this->backup();
        $i1 = $this->backup();
        $this->assertSame($i1->id, app(DisasterRecovery::class)->latestBackup($this->server->fresh())?->id);

        // Zepsuty łańcuch (pełna nieudana) — DR bierze starszą kompletną kopię albo nic.
        $full->forceFill(['status' => Backup::STATUS_FAILED])->save();
        $this->assertFalse($i1->fresh()->isRestorable());
        $this->assertNull(app(DisasterRecovery::class)->latestBackup($this->server->fresh()));

        app(RemoteBackups::class)->delete($full->fresh());
        $this->assertSame(0, Backup::query()->count());
    }

    public function test_pola_planu_i_oznaczenie_w_panelu(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->put(route('panel.admin.backups.plans.update', $this->plan), [
            'name' => 'P', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3, 'retention' => 5, 'full_every' => 10, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertFalse($this->plan->fresh()->incremental);
        $this->assertSame(10, $this->plan->fresh()->full_every);
        $this->put(route('panel.admin.backups.plans.update', $this->plan), [
            'name' => 'P', 'backup_target_id' => $this->target->id, 'interval_hours' => 24, 'hour' => 3, 'retention' => 5, 'full_every' => 4, 'incremental' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($this->plan->fresh()->incremental);

        $this->backup();
        $this->backup();
        $this->get(route('panel.servers.show', $this->server))->assertOk()->assertSee('przyrostowa');
        $this->get(route('panel.admin.backups'))->assertOk()->assertSee('Rodzaj kopii')->assertSee('różnicowe (od ostatniej pełnej)');
    }
}
