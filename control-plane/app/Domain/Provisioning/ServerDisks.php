<?php

namespace App\Domain\Provisioning;

use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerDisk;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Dyski maszyny KVM: dodatkowe dyski (vdb–vdh) i powiększanie każdego dysku,
 * także na działającej maszynie. Miejsce na węźle jest rezerwowane od razu;
 * gdy węzeł odmówi, zmiana w panelu jest cofana (patrz revert()).
 */
class ServerDisks
{
    public function __construct(private readonly ServerProvisioner $provisioner) {}

    public function add(Server $server, int $sizeGb, ?User $actor = null): ServerJob
    {
        $this->assertManageable($server);

        return $this->change($server, $actor, function () use ($server, $sizeGb) {
            $used = $server->disks()->pluck('slot')->all();
            $free = array_values(array_diff(range(1, ServerDisk::MAX_SLOTS), $used));
            if ($free === []) {
                throw new \DomainException(__('Maszyna ma już maksymalną liczbę dodatkowych dysków (:max).', ['max' => ServerDisk::MAX_SLOTS]));
            }
            $this->reserve($server, $sizeGb);
            $disk = $server->disks()->create(['slot' => $free[0], 'size_gb' => $sizeGb]);

            return ['disk.added', ['dev' => $disk->dev(), 'size_gb' => $sizeGb]];
        });
    }

    /** Powiększenie dysku dodatkowego albo (null) głównego. */
    public function grow(Server $server, ?ServerDisk $disk, int $sizeGb, ?User $actor = null): ServerJob
    {
        $this->assertManageable($server);
        $current = $disk ? $disk->size_gb : (int) $server->disk_gb;
        if ($sizeGb <= $current) {
            throw new \DomainException(__('Nowy rozmiar musi być większy niż obecny (:size GB) — dysków nie da się zmniejszyć.', ['size' => $current]));
        }

        return $this->change($server, $actor, function () use ($server, $disk, $sizeGb, $current) {
            $this->reserve($server, $sizeGb - $current);
            $disk ? $disk->update(['size_gb' => $sizeGb]) : $server->forceFill(['disk_gb' => $sizeGb])->save();

            return ['disk.grown', ['dev' => $disk?->dev() ?? 'vda', 'from_gb' => $current, 'size_gb' => $sizeGb]];
        });
    }

    public function remove(ServerDisk $disk, ?User $actor = null): ServerJob
    {
        $server = $disk->server;
        $this->assertManageable($server);

        return $this->change($server, $actor, function () use ($server, $disk) {
            $this->reserve($server, -$disk->size_gb);
            $disk->delete();

            return ['disk.removed', ['dev' => $disk->dev(), 'size_gb' => $disk->size_gb]];
        }, deleteRemoved: true);
    }

    /** @return list<array{slot: int, size_gb: int}> */
    public static function payload(Server $server): array
    {
        return $server->isContainer() ? [] : $server->disks()->get()->map(fn (ServerDisk $d) => ['slot' => $d->slot, 'size_gb' => $d->size_gb])->values()->all();
    }

    /** Węzeł odrzucił zmianę — przywracamy stan dysków i zajętość sprzed zlecenia. */
    public function revert(ServerJob $job): void
    {
        $before = $job->payload['before'] ?? null;
        $server = $job->server;
        if ($before === null || $server === null) {
            return;
        }
        DB::transaction(function () use ($server, $before) {
            $delta = ((int) $before['disk_gb'] + array_sum(array_column($before['disks'], 'size_gb'))) - $server->totalDiskGb();
            $server->disks()->delete();
            foreach ($before['disks'] as $disk) {
                $server->disks()->create($disk);
            }
            $server->forceFill(['disk_gb' => (int) $before['disk_gb']])->save();
            $this->adjustNode($server, $delta);
        });
    }

    private function change(Server $server, ?User $actor, \Closure $apply, bool $deleteRemoved = false): ServerJob
    {
        $before = ['disk_gb' => (int) $server->disk_gb, 'disks' => self::payload($server)];
        [$event, $context] = DB::transaction($apply);
        AuditLog::record('server.'.$event, $server, $context, $actor);

        return $this->provisioner->syncDisks($server, $actor, ['before' => $before, 'delete_removed' => $deleteRemoved]);
    }

    private function reserve(Server $server, int $deltaGb): void
    {
        if ($deltaGb > 0) {
            $node = Hypervisor::query()->lockForUpdate()->findOrFail($server->hypervisor_id);
            if (! $node->hasCapacityFor(0, 0, $deltaGb)) {
                throw new \DomainException(__('Węzeł :name nie ma :size GB wolnego miejsca na dysk.', ['name' => $node->name, 'size' => $deltaGb]));
            }
        }
        $this->adjustNode($server, $deltaGb);
    }

    private function adjustNode(Server $server, int $deltaGb): void
    {
        $node = Hypervisor::query()->lockForUpdate()->find($server->hypervisor_id);
        $node?->forceFill(['disk_gb_used' => max(0, $node->disk_gb_used + $deltaGb)])->save();
    }

    private function assertManageable(Server $server): void
    {
        if ($server->isContainer()) {
            throw new \DomainException(__('Dodatkowe dyski są dostępne tylko dla maszyn KVM.'));
        }
        if ($server->agent_uuid === null || $server->hypervisor_id === null || $server->state->isTransitioning()) {
            throw new \DomainException(__('Maszyna nie jest gotowa — spróbuj, gdy zakończy bieżącą operację.'));
        }
        if ($server->isElevated()) {
            throw new \DomainException(__('Maszyna ma aktywny Elevate — najpierw zatwierdź albo odrzuć zmiany dysku.'));
        }
    }
}
