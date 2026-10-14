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
    protected $fillable = ['user_id', 'hypervisor_id', 'name', 'cidr'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
