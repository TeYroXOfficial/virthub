<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Plan zasobów aplikacji: pamięć, procesor, dysk i liczba portów. */
class AppPlan extends Model
{
    protected $fillable = ['name', 'memory_mb', 'cpu_percent', 'disk_mb', 'ports', 'price_hint_cents', 'currency', 'is_active'];

    protected $attributes = ['is_active' => true, 'cpu_percent' => 0, 'ports' => 1, 'currency' => 'PLN'];

    protected function casts(): array
    {
        return [
            'memory_mb' => 'integer',
            'cpu_percent' => 'integer',
            'disk_mb' => 'integer',
            'ports' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<AppServer, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(AppServer::class);
    }

    /** @param  Builder<AppPlan>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
