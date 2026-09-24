<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupa węzłów współdzielących pulę adresów — typowo węzły w jednej
 * lokalizacji, podpięte do tego samego segmentu sieci dostawcy.
 */
class HypervisorGroup extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description'];

    /** @return HasMany<Hypervisor, $this> */
    public function hypervisors(): HasMany
    {
        return $this->hasMany(Hypervisor::class);
    }

    /** @return HasMany<IpPool, $this> */
    public function ipPools(): HasMany
    {
        return $this->hasMany(IpPool::class);
    }
}
