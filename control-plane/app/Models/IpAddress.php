<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'ip_pool_id',
        'hypervisor_id',
        'server_id',
        'address',
        'scope_key',
        'version',
        'is_primary',
        'is_reserved',
        'rdns',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_primary' => 'boolean',
            'is_reserved' => 'boolean',
            'assigned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IpPool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    /** @param Builder<IpAddress> $query */
    public function scopeAssignable(Builder $query): void
    {
        $query->whereNull('server_id')->where('is_reserved', false);
    }

    public function cidr(): string
    {
        return $this->address.'/'.$this->pool->prefix;
    }

    public function isNat(): bool
    {
        return $this->pool?->isNat() ?? false;
    }

    /**
     * Przekierowane porty adresu za NAT-em: pierwszy prowadzi na SSH (22),
     * pozostałe 1:1 na te same numery w maszynie.
     *
     * @return array{from: int, to: int, ssh: int}|null
     */
    public function natPorts(): ?array
    {
        $ports = $this->pool?->natPortsFor($this->address);

        return $ports === null ? null : [...$ports, 'ssh' => $ports['from']];
    }
}
