<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Addon extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'price', 'is_public'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    /** @return HasMany<AddonVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AddonVersion::class);
    }

    /** @return BelongsToMany<License, $this> */
    public function licenses(): BelongsToMany
    {
        return $this->belongsToMany(License::class)->withPivot('expires_at')->withTimestamps();
    }

    /** Najnowsza opublikowana wersja (porównanie wersji semantycznych). */
    public function latest(): ?AddonVersion
    {
        return $this->versions->where('is_published', true)
            ->sort(fn (AddonVersion $a, AddonVersion $b) => version_compare($b->version, $a->version))->first();
    }
}
