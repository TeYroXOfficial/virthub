<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OsTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'family',
        'version',
        'image_file',
        'min_disk_gb',
        'cloud_init_support',
        'is_active',
        'icon',
    ];

    protected function casts(): array
    {
        return [
            'cloud_init_support' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @param Builder<OsTemplate> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Szablon bez cloud-init wymagałby ręcznej instalacji z ISO — do czasu
     * wsparcia tej ścieżki (Windows, Faza po MVP) nie da się go zamówić.
     */
    public function isSelfService(): bool
    {
        return $this->cloud_init_support;
    }
}
