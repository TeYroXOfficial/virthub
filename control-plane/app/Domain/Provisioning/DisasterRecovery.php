<?php

namespace App\Domain\Provisioning;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Enums\ServerState;
use App\Enums\Virtualization;
use App\Jobs\DrSnapshotJob;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Disaster recovery hypervisora.
 *
 * Kopie dysków robi zwykły mechanizm kopii na cele zewnętrzne — węzeł może mieć
 * plan DR obejmujący maszyny bez własnego planu. Do tego okresowy zrzut
 * konfiguracji węzła (XML domen, stan sieci, magazyny) na cel kopii.
 *
 * Po awarii administrator wskazuje zastępczy węzeł: panel przenosi na niego
 * pule adresów IP awaryjnego węzła, rezerwuje zasoby i dla każdej maszyny
 * zleca odtworzenie z najnowszej kopii. Panel jest źródłem prawdy o sieci,
 * zasobach i właścicielach, więc maszyna wstaje z tymi samymi adresami.
 */
class DisasterRecovery
{
    public function __construct(
        private readonly ServerProvisioner $provisioner,
        private readonly HypervisorSelector $selector,
    ) {}

    /** Maszyny węzła z ich najnowszą gotową kopią (albo null). @return Collection<int, array{server: Server, backup: ?Backup}> */
    public function inventory(Hypervisor $node): Collection
    {
        return Server::query()->where('hypervisor_id', $node->id)->orderBy('hostname')->get()
            ->map(fn (Server $server) => ['server' => $server, 'backup' => $this->latestBackup($server)]);
    }

    public function latestBackup(Server $server): ?Backup
    {
        return $server->backups()->with('target')->where('type', Backup::TYPE_REMOTE)->where('status', Backup::STATUS_READY)
            ->whereNotNull('remote_key')->latest('created_at')->latest('id')->first();
    }

    /**
     * Odtwarza maszyny awaryjnego węzła na zastępczym.
     *
     * @return array{queued: list<int>, skipped: array<int, string>, pools: int}
     */
    public function recover(Hypervisor $failed, Hypervisor $replacement, ?User $actor = null): array
    {
        if ($failed->is($replacement)) {
            throw new \DomainException(__('Zastępczy węzeł musi być inny niż awaryjny.'));
        }
        if ($replacement->virtualization !== Virtualization::Kvm) {
            throw new \DomainException(__('Zastępczy węzeł musi uruchamiać maszyny KVM.'));
        }
        if (! Hypervisor::query()->available()->whereKey($replacement->id)->exists()) {
            throw new \DomainException(__('Zastępczy węzeł :name nie jest dostępny (online i przyjmuje maszyny).', ['name' => $replacement->name]));
        }
        if ($failed->status === Hypervisor::STATUS_ONLINE && $failed->last_seen_at?->gt(now()->subMinutes(5))) {
            throw new \DomainException(__('Węzeł :name wciąż raportuje stan — przełącz go w tryb serwisowy, zanim odtworzysz maszyny gdzie indziej.', ['name' => $failed->name]));
        }

        $queued = [];
        $skipped = [];
        $pools = 0;
        $items = $this->inventory($failed);

        DB::transaction(function () use ($failed, $replacement, $items, $actor, &$queued, &$skipped, &$pools) {
            // Adresy jadą razem z maszynami — ta sama sieć L2 albo przeniesiona trasa po stronie dostawcy.
            $poolIds = IpPool::query()->where('hypervisor_id', $failed->id)->pluck('id');
            $pools = $poolIds->count();
            IpPool::query()->whereIn('id', $poolIds)->update(['hypervisor_id' => $replacement->id]);
            IpAddress::query()->whereIn('ip_pool_id', $poolIds)->update(['hypervisor_id' => $replacement->id]);
            $failed->forceFill(['accepts_new_servers' => false, 'status' => Hypervisor::STATUS_MAINTENANCE])->save();

            foreach ($items as ['server' => $server, 'backup' => $backup]) {
                $reason = match (true) {
                    $server->isContainer() => __('kontener — kopie na cele zewnętrzne obejmują tylko KVM'),
                    $backup === null => __('brak gotowej kopii na celu zewnętrznym'),
                    $server->datastore?->isCeph() === true => __('dysk w Cephie — odtwarzanie z kopii wymaga magazynu katalogowego'),
                    ! $replacement->hasCapacityFor($server->vcpu, $server->ram_mb, $server->disk_gb) => __('brak wolnych zasobów na zastępczym węźle'),
                    default => null,
                };
                if ($reason !== null) {
                    $skipped[$server->id] = $reason;
                    $server->markState(ServerState::Error, __('Węzeł :node uległ awarii; nie odtworzono automatycznie: :reason.', ['node' => $failed->name, 'reason' => $reason]));

                    continue;
                }
                $this->selector->release($server);
                $this->selector->reserve($server, $replacement->fresh());
                // Przypisanie magazynu: katalog musi być przypięty do nowego węzła, inaczej — domyślny.
                if ($server->datastore && ! $server->datastore->hypervisors()->whereKey($replacement->id)->exists()) {
                    $server->forceFill(['datastore_id' => null])->save();
                }
                $server->pciAssets()->update(['server_id' => null]);
                $this->provisioner->recover($server->fresh(), $backup, $actor);
                $queued[] = $server->id;
            }
        });

        AuditLog::record('hypervisor.recovered', $failed, [
            'replacement' => $replacement->name, 'queued' => $queued, 'skipped' => $skipped, 'pools' => $pools,
        ], $actor);

        return ['queued' => $queued, 'skipped' => $skipped, 'pools' => $pools];
    }

    /** Zleca zrzut konfiguracji węzła (wynik odbiera DrSnapshotJob). */
    public function snapshot(Hypervisor $node): void
    {
        $target = $node->drTarget;
        if ($target === null || ! $target->is_active) {
            throw new \DomainException(__('Węzeł nie ma aktywnego celu zrzutów DR.'));
        }
        $key = sprintf('dr/node-%d/%s.json', $node->id, now()->utc()->format('Ymd-His'));
        try {
            $jobId = (new AgentClient($node))->drSnapshot($key, $target->toAgentPayload());
        } catch (AgentException $e) {
            $node->forceFill(['dr_last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw new \DomainException($e->getMessage());
        }
        $node->forceFill(['dr_snapshot_job_id' => $jobId, 'dr_last_error' => null])->save();
        DrSnapshotJob::dispatch($node->id, $key)->delay(now()->addSeconds(5));
    }

    /** Harmonogram: zrzuty, których czas minął. */
    public function runDue(): int
    {
        $count = 0;
        Hypervisor::query()->whereNotNull('dr_target_id')->whereNull('dr_snapshot_job_id')->get()
            ->filter(fn (Hypervisor $n) => $n->dr_last_snapshot_at === null
                || $n->dr_last_snapshot_at->addHours(max(1, $n->dr_snapshot_hours))->subMinutes(10)->isPast())
            ->each(function (Hypervisor $node) use (&$count) {
                try {
                    $this->snapshot($node);
                    $count++;
                } catch (\DomainException) {
                    // Błąd zapisany w dr_last_error — widać go na stronie DR.
                }
            });

        return $count;
    }
}
