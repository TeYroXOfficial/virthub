<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpPool extends Model
{
    use HasFactory;

    protected $fillable = [
        'hypervisor_id',
        'name',
        'cidr',
        'version',
        'gateway',
        'prefix',
        'nameservers',
    ];

    protected function casts(): array
    {
        return ['nameservers' => 'array'];
    }

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    /** @return HasMany<IpAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /** @return list<string> */
    public function nameserverList(): array
    {
        return $this->nameservers ?: ['1.1.1.1', '9.9.9.9'];
    }
}
