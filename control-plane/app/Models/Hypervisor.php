<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Hypervisor extends Model
{
    use HasFactory;

    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_MAINTENANCE = 'maintenance';

    /** Po tylu sekundach bez heartbeatu node uznajemy za niedostępny. */
    public const HEARTBEAT_TIMEOUT = 120;

    protected $fillable = [
        'name',
        'hostname',
        'agent_url',
        'agent_token',
        'callback_secret',
        'status',
        'cpu_cores_total',
        'ram_mb_total',
        'disk_gb_total',
        'accepts_new_servers',
        'bridge',
    ];

    protected $hidden = ['agent_token', 'callback_secret', 'enrollment_token_hash'];

    protected function casts(): array
    {
        return [
            // Sekrety agenta nigdy nie leżą w bazie jawnie — wyciek dumpu bazy
            // nie może dać komuś kontroli nad hypervisorem.
            'agent_token' => 'encrypted',
            'callback_secret' => 'encrypted',
            'last_seen_at' => 'datetime',
            'last_health' => 'array',
            'accepts_new_servers' => 'boolean',
            'enrollment_expires_at' => 'datetime',
            'enrolled_at' => 'datetime',
        ];
    }

    /** Czy węzeł czeka jeszcze na wykonanie polecenia instalacyjnego. */
    public function isAwaitingEnrollment(): bool
    {
        return $this->enrolled_at === null
            && $this->enrollment_expires_at !== null
            && $this->enrollment_expires_at->isFuture();
    }

    public function enrollmentExpired(): bool
    {
        return $this->enrolled_at === null
            && ($this->enrollment_expires_at === null || $this->enrollment_expires_at->isPast());
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /** @return HasMany<IpPool, $this> */
    public function ipPools(): HasMany
    {
        return $this->hasMany(IpPool::class);
    }

    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ONLINE
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::HEARTBEAT_TIMEOUT));
    }

    public function cpuCoresFree(): int
    {
        return max(0, $this->cpu_cores_total - $this->cpu_cores_used);
    }

    public function ramMbFree(): int
    {
        return max(0, $this->ram_mb_total - $this->ram_mb_used);
    }

    public function diskGbFree(): int
    {
        return max(0, $this->disk_gb_total - $this->disk_gb_used);
    }

    public function hasCapacityFor(int $vcpu, int $ramMb, int $diskGb): bool
    {
        return $this->cpuCoresFree() >= $vcpu
            && $this->ramMbFree() >= $ramMb
            && $this->diskGbFree() >= $diskGb;
    }

    /**
     * Zajętość w procentach — używana przy doborze node'a i na liście w panelu.
     * Bierzemy najbardziej obciążony wymiar, bo to on pierwszy się skończy.
     */
    public function utilisationPercent(): int
    {
        $ratios = array_filter([
            $this->cpu_cores_total > 0 ? $this->cpu_cores_used / $this->cpu_cores_total : null,
            $this->ram_mb_total > 0 ? $this->ram_mb_used / $this->ram_mb_total : null,
            $this->disk_gb_total > 0 ? $this->disk_gb_used / $this->disk_gb_total : null,
        ], fn ($r) => $r !== null);

        return $ratios === [] ? 0 : (int) round(max($ratios) * 100);
    }

    /** @param Builder<Hypervisor> $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('status', self::STATUS_ONLINE)
            ->where('accepts_new_servers', true)
            ->where('last_seen_at', '>=', now()->subSeconds(self::HEARTBEAT_TIMEOUT));
    }
}
