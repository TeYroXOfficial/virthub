<?php

namespace App\Domain\Network;

use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use App\Models\IpAddress;
use App\Models\IpPool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grupy węzłów — wspólne dla panelu i API administratora.
 *
 * Jedyna nieoczywista reguła: węzeł nie może opuścić grupy, dopóki stoją na
 * nim maszyny z adresami z puli tej grupy. Po wyjściu z grupy pula przestaje
 * go obejmować, a adresy działających maszyn zostałyby „niczyje".
 */
class HypervisorGroupManager
{
    /** @param  list<int|string>  $hypervisorIds */
    public function create(string $name, ?string $description, array $hypervisorIds = []): HypervisorGroup
    {
        return DB::transaction(function () use ($name, $description, $hypervisorIds) {
            $group = HypervisorGroup::create(['name' => $name, 'description' => $description]);
            $this->syncMembers($group, $hypervisorIds);
            AuditLog::record('hypervisor_group.created', $group, ['name' => $group->name]);

            return $group;
        });
    }

    /**
     * @param  list<int|string>|null  $hypervisorIds  null = skład bez zmian
     *
     * @throws ValidationException
     */
    public function update(HypervisorGroup $group, string $name, ?string $description, ?array $hypervisorIds): HypervisorGroup
    {
        return DB::transaction(function () use ($group, $name, $description, $hypervisorIds) {
            $group->update(['name' => $name, 'description' => $description]);

            if ($hypervisorIds !== null) {
                $this->syncMembers($group, $hypervisorIds);
            }

            AuditLog::record('hypervisor_group.updated', $group, ['hypervisor_ids' => $hypervisorIds]);

            return $group;
        });
    }

    /** @throws ValidationException */
    public function delete(HypervisorGroup $group): void
    {
        if ($group->ipPools()->exists()) {
            throw ValidationException::withMessages([
                'group' => "Grupa {$group->name} ma pule adresów. Usuń je najpierw — "
                    .'razem z grupą zniknęłyby adresy działających maszyn.',
            ]);
        }

        AuditLog::record('hypervisor_group.deleted', $group, ['name' => $group->name]);
        $group->delete();
    }

    /**
     * Przenosi węzeł do grupy (albo wyjmuje z grupy, gdy $group = null).
     *
     * @throws ValidationException
     */
    public function assign(Hypervisor $hypervisor, ?HypervisorGroup $group, string $field = 'hypervisor_group_id'): void
    {
        if ($hypervisor->hypervisor_group_id === $group?->id) {
            return;
        }

        $this->assertCanLeave($hypervisor, $field);
        $hypervisor->forceFill(['hypervisor_group_id' => $group?->id])->save();
    }

    /** @throws ValidationException */
    public function assertCanLeave(Hypervisor $hypervisor, string $field = 'hypervisor_group_id'): void
    {
        if ($hypervisor->hypervisor_group_id === null) {
            return;
        }

        $inUse = IpAddress::query()
            ->whereIn('ip_pool_id', IpPool::query()
                ->where('hypervisor_group_id', $hypervisor->hypervisor_group_id)
                ->select('id'))
            ->where('hypervisor_id', $hypervisor->id)
            ->whereNotNull('server_id')
            ->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                $field => "Węzeł {$hypervisor->name} ma maszyny z adresami z puli swojej grupy "
                    .'— nie może jej opuścić, dopóki te adresy są w użyciu.',
            ]);
        }
    }

    /**
     * @param  list<int|string>  $ids
     *
     * @throws ValidationException
     */
    private function syncMembers(HypervisorGroup $group, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        // Opuszczają grupę: jej dotychczasowi członkowie spoza listy oraz
        // węzły z listy, które dziś należą do innej grupy.
        $leaving = Hypervisor::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('hypervisor_group_id', $group->id)->whereNotIn('id', $ids))
                ->orWhere(fn ($q) => $q->whereIn('id', $ids)
                    ->whereNotNull('hypervisor_group_id')
                    ->where('hypervisor_group_id', '!=', $group->id)))
            ->get();

        $leaving->each(fn (Hypervisor $node) => $this->assertCanLeave($node, 'hypervisor_ids'));

        Hypervisor::query()
            ->where('hypervisor_group_id', $group->id)
            ->whereNotIn('id', $ids)
            ->update(['hypervisor_group_id' => null]);

        Hypervisor::query()->whereKey($ids)->update(['hypervisor_group_id' => $group->id]);
    }
}
