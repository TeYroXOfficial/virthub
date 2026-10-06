<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class License extends Model
{
    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    protected $fillable = ['key', 'owner_name', 'owner_email', 'domain', 'status', 'expires_at', 'notes', 'last_seen_at', 'last_ip', 'panel_version'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /** Klucz w postaci VH-XXXX-XXXX-XXXX-XXXX (bez mylących znaków 0/O, 1/I). */
    public static function generateKey(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $parts = [];
            for ($i = 0; $i < 4; $i++) {
                $parts[] = implode('', array_map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)], range(1, 4)));
            }
            $key = 'VH-'.implode('-', $parts);
        } while (self::query()->where('key', $key)->exists());

        return $key;
    }

    /** @return BelongsToMany<Addon, $this> */
    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class)->withPivot('expires_at')->withTimestamps();
    }

    /** @return HasMany<LicenseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(LicenseEvent::class)->latest('id');
    }

    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** Addony z ważnym dostępem. */
    public function activeAddons()
    {
        return $this->addons->filter(fn (Addon $a) => $a->pivot->expires_at === null || $a->pivot->expires_at > now());
    }

    public function maskedKey(): string
    {
        return Str::substr($this->key, 0, 7).'-••••-••••-'.Str::substr($this->key, -4);
    }
}
