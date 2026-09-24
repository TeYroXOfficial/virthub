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

    protected $fillable = ['name', 'description', 'location', 'is_public', 'accepts_new_servers', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'accepts_new_servers' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @param \Illuminate\Database\Eloquent\Builder<HypervisorGroup> $query */
    public function scopeOrdered(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /** Nazwa dla klienta: lokalizacja, a gdy jej brak — nazwa grupy. */
    public function publicName(): string
    {
        return $this->location ?: $this->name;
    }

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
