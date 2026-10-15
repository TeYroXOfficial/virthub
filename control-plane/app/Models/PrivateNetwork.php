<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sieć prywatna klienta: izolowany mostek `vhp<id>` na jednym węźle (bez
 * łącza w górę) — widzą się w niej tylko podłączone maszyny tego klienta.
 */
class PrivateNetwork extends Model
{
    protected $fillable = ['user_id', 'hypervisor_id', 'hypervisor_group_id', 'name', 'cidr'];

    /** Sieć rozpięta na grupę węzłów (tunel VXLAN), a nie tylko na węzeł, na którym powstała. */
    public function spansGroup(): bool
    {
        return $this->hypervisor_group_id !== null;
    }

    public function vni(): int
    {
        return 100000 + $this->id;
    }

    /** Czy maszyna na tym węźle może dołączyć do sieci. */
    public function reachableFrom(?Hypervisor $node): bool
    {
        if ($node === null) {
            return false;
        }
        if (! $this->spansGroup()) {
            return $node->id === $this->hypervisor_id;
        }

        return $node->hypervisor_group_id === $this->hypervisor_group_id && filled($node->vxlan_address);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<HypervisorGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(HypervisorGroup::class, 'hypervisor_group_id');
    }

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    /** @return HasMany<ServerNic, $this> */
    public function nics(): HasMany
    {
        return $this->hasMany(ServerNic::class);
    }

    public function prefix(): int
    {
        return (int) explode('/', $this->cidr)[1];
    }

    public function bridge(): string
    {
        return 'vhp'.$this->id;
    }
}
