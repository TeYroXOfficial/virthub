<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpsPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'vcpu',
        'ram_mb',
        'disk_gb',
        'bandwidth_gb',
        'ip_count',
        'price_hint_cents',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** @param Builder<VpsPackage> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function ramGb(): float
    {
        return round($this->ram_mb / 1024, 1);
    }
}
