<?php

namespace App\Domain\Provisioning;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Jobs\RunServerActionJob;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\BackupPlan;
use App\Models\BackupTarget;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Kopie na zewnętrzne cele (katalog, S3, SFTP) według planów.
 *
 * Harmonogram co 15 minut sprawdza maszyny z planem: gdy od ostatniej udanej
 * kopii minął interwał (a dla planów dobowych — wybiła godzina startu),
 * zleca agentowi kopię. Po sukcesie najstarsze kopie ponad retencję są
 * usuwane z celu.
 */
class RemoteBackups
{
    public function __construct(private readonly ServerProvisioner $provisioner) {}

    /** Ręczna albo planowa kopia. */
    public function create(Server $server, BackupTarget $target, bool $compress = true, ?User $actor = null, ?BackupPlan $plan = null): ServerJob
    {
        if ($server->isContainer()) {
            throw new \DomainException(__('Kopie na zewnętrzne cele są dostępne tylko dla maszyn KVM.'));
        }
        if ($server->isElevated()) {
            throw new \DomainException(__('Maszyna ma aktywny Elevate — najpierw zatwierdź albo odrzuć zmiany dysku.'));
        }
        if ($server->inRescue()) {
            throw new \DomainException(__('Maszyna jest w trybie ratunkowym — najpierw go zakończ.'));
        }
        if ($server->agent_uuid === null || $server->state->isTransitioning()) {
            throw new \DomainException(__('Maszyna nie jest gotowa do wykonania kopii.'));
        }
        // Dysk w Cephie: zawsze kopia pełna (eksport migawki RBD) — bez bitmap libvirt.
        $mode = $server->datastore?->isCeph() ? 'full' : ($plan?->backupMode() ?? 'full');
        $parent = $mode !== 'full' ? $this->chainParent($server, $target, $plan, $mode) : null;
        $name = ($parent ? 'incr-' : 'backup-').now()->format('Ymd-His');
        $backup = Backup::create([
            'parent_id' => $parent?->id,
            'kind' => $parent ? Backup::KIND_INCREMENTAL : Backup::KIND_FULL,
            'server_id' => $server->id,
            'name' => $name,
            'type' => Backup::TYPE_REMOTE,
            'status' => Backup::STATUS_CREATING,
            'backup_target_id' => $target->id,
            'remote_key' => 'vm'.$server->id.'/'.$name.'.qcow2',
        ]);
        if ($mode !== 'full') {
            // Nazwę checkpointu zna panel; agent zwróci ją w wyniku, jeśli go utworzył
            // (kopia różnicowa nie tworzy nowego — liczy się od checkpointu pełnej).
            $backup->forceFill(['checkpoint' => 'vh-'.$backup->id])->save();
        }
        $job = ServerJob::create([
            'server_id' => $server->id,
            'hypervisor_id' => $server->hypervisor_id,
            'action' => 'backup',
            'status' => ServerJob::STATUS_QUEUED,
            'payload' => ['backup_id' => $backup->id, 'compress' => $compress, 'differential' => $mode === 'differential' && $parent !== null],
            'user_id' => $actor?->id,
        ]);
        AuditLog::record('server.backup', $server, ['target' => $target->name, 'key' => $backup->remote_key], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    /**
     * Ostatnia gotowa kopia z aktualnym checkpointem na tym samym celu, jeśli
     * łańcuch jest krótszy niż „pełna co N” — inaczej czas na kopię pełną.
     */
    private function chainParent(Server $server, BackupTarget $target, BackupPlan $plan, string $mode = 'incremental'): ?Backup
    {
        $ready = fn () => $server->backups()->where('type', Backup::TYPE_REMOTE)->where('status', Backup::STATUS_READY)
            ->where('backup_target_id', $target->id)->latest('created_at')->latest('id');
        if ($mode === 'differential') {
            // Rodzic to ostatnia kopia pełna z checkpointem; różnicowe liczą się od niej.
            $full = $ready()->where('kind', Backup::KIND_FULL)->whereNotNull('checkpoint')->first();
            if ($full === null || $ready()->first()?->kind === Backup::KIND_INCREMENTAL && $ready()->first()->parent_id !== $full->id) {
                return null;
            }

            return $full->children()->count() + 1 < max(1, $plan->full_every) ? $full : null;
        }
        $last = $ready()->first();
        if ($last === null || $last->checkpoint === null || ! $last->chainComplete()) {
            return null;
        }

        return count($last->ancestors()) + 1 < max(1, $plan->full_every) ? $last : null;
    }

    /**
     * Przywrócenie kopii innej maszyny (personel): dysk zastępuje dysk docelowej.
     * Kopia większej maszyny nie zmieści się na mniejszym dysku docelowej.
     */
    public function restoreOnto(Server $target, Backup $backup, ?User $actor = null): ServerJob
    {
        $source = $backup->server()->withTrashed()->first();
        if ($target->isContainer()) {
            throw new \DomainException(__('Kopie na zewnętrzne cele są dostępne tylko dla maszyn KVM.'));
        }
        if ($source !== null && $source->id !== $target->id && (int) $source->disk_gb > (int) $target->disk_gb) {
            throw new \DomainException(__('Kopia pochodzi z dysku :from GB, a maszyna docelowa ma :to GB — powiększ jej dysk przed przywróceniem.', ['from' => $source->disk_gb, 'to' => $target->disk_gb]));
        }
        AuditLog::record('server.backup_restore_cross', $target, ['backup' => $backup->name, 'source_server_id' => $source?->id], $actor);

        return $this->restore($target, $backup, $actor);
    }

    public function restore(Server $server, Backup $backup, ?User $actor = null): ServerJob
    {
        if (! $backup->isRemote() || ! $backup->isRestorable() || $backup->target === null) {
            throw new \DomainException(__('Kopia :name nie jest gotowa do przywrócenia (status: :status).', ['name' => $backup->name, 'status' => $backup->status]));
        }
        if ($server->isElevated()) {
            throw new \DomainException(__('Maszyna ma aktywny Elevate — najpierw zatwierdź albo odrzuć zmiany dysku.'));
        }
        if ($server->inRescue()) {
            throw new \DomainException(__('Maszyna jest w trybie ratunkowym — najpierw go zakończ.'));
        }
        $job = ServerJob::create([
            'server_id' => $server->id,
            'hypervisor_id' => $server->hypervisor_id,
            'action' => 'backup_restore',
            'status' => ServerJob::STATUS_QUEUED,
            'payload' => ['backup_id' => $backup->id],
            'user_id' => $actor?->id,
        ]);
        AuditLog::record('server.backup_restore', $server, ['name' => $backup->name], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    /** Usunięcie kopii z celu (przez węzeł maszyny) i z panelu. */
    public function delete(Backup $backup): void
    {
        // Przyrostowe zależne od tej kopii bez niej są bezużyteczne.
        foreach ($backup->children()->get() as $child) {
            $this->delete($child);
        }
        $server = $backup->server()->withTrashed()->first();
        if ($backup->target && $backup->remote_key && $server?->hypervisor) {
            try {
                (new AgentClient($server->hypervisor))->deleteBackups([$backup->remote_key], $backup->target->toAgentPayload());
            } catch (AgentException) {
                // Węzeł niedostępny — plik zostaje w celu; panel i tak o nim zapomina.
            }
        }
        $backup->delete();
    }

    /** Kopie ponad retencję planu (najstarsze). */
    public function prune(Server $server): int
    {
        $plan = $server->effectiveBackupPlan();
        if ($plan === null) {
            return 0;
        }
        $ready = $server->backups()->where('type', Backup::TYPE_REMOTE)->where('status', Backup::STATUS_READY)
            ->where('backup_target_id', $plan->backup_target_id)
            ->orderByDesc('created_at')->orderByDesc('id')->take(2000)->get();
        // Zostają najnowsze N, kopie z retencji GFS (najnowsza z każdego dnia / tygodnia /
        // miesiąca) oraz ogniwa, od których zależą (pełna i wcześniejsze przyrostowe).
        $kept = $ready->take($plan->retention)->keyBy('id');
        foreach (['keep_daily' => 'Y-m-d', 'keep_weekly' => 'o-W', 'keep_monthly' => 'Y-m'] as $field => $format) {
            $periods = [];
            foreach ($ready as $backup) {
                $period = $backup->created_at->format($format);
                if (isset($periods[$period])) {
                    continue;
                }
                if (count($periods) >= (int) $plan->{$field}) {
                    break;
                }
                $periods[$period] = true;
                $kept[$backup->id] = $backup;
            }
        }
        $keep = [];
        foreach ($kept as $backup) {
            $keep[$backup->id] = true;
            foreach ($backup->ancestors() as $ancestor) {
                $keep[$ancestor->id] = true;
            }
        }
        $deleted = 0;
        foreach ($ready as $backup) {
            if (! isset($keep[$backup->id]) && Backup::query()->whereKey($backup->id)->exists()) {
                $deleted += 1 + $backup->children()->count();
                $this->delete($backup);
            }
        }

        return $deleted;
    }

    /** Planowe kopie, których czas minął. */
    public function runDue(?Carbon $now = null): int
    {
        $now ??= now();
        $queued = 0;
        // Własny plan maszyny albo plan DR jej węzła.
        $drNodes = Hypervisor::query()->whereNotNull('dr_backup_plan_id')->pluck('id');
        $servers = Server::query()->whereNotNull('agent_uuid')->whereNull('suspended_at')
            ->where(fn ($q) => $q->whereNotNull('backup_plan_id')->orWhereIn('hypervisor_id', $drNodes))
            ->with(['backupPlan.target', 'hypervisor.drBackupPlan.target'])->get();
        foreach ($servers as $server) {
            $plan = $server->effectiveBackupPlan();
            if (! $plan?->is_active || ! $plan->target?->is_active || $server->isContainer() || $server->isElevated() || $server->inRescue()) {
                continue;
            }
            if (! $plan->runsOn($now->dayOfWeek)) {
                continue; // plan ograniczony do wybranych dni tygodnia
            }
            $busy = $server->backups()->where('type', Backup::TYPE_REMOTE)->where('status', Backup::STATUS_CREATING)
                ->where('created_at', '>', $now->copy()->subHours(12))->exists();
            if ($busy || $server->state->isTransitioning()) {
                continue;
            }
            $last = $server->backups()->where('type', Backup::TYPE_REMOTE)->where('status', Backup::STATUS_READY)->max('created_at');
            $due = $last === null || Carbon::parse($last)->addHours($plan->interval_hours)->subMinutes(15)->lte($now);
            if ($due && $plan->interval_hours >= 24 && $now->hour !== $plan->hour && $last !== null) {
                $due = false; // plany dobowe i dłuższe startują o swojej godzinie
            }
            if ($due) {
                try {
                    $this->create($server, $plan->target, $plan->compress, plan: $plan);
                    $queued++;
                } catch (\DomainException) {
                }
            }
        }

        return $queued;
    }
}
