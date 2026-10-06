<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * VPS u dostawcy zewnętrznego (reselling). Klient widzi go jak zwykły VPS —
 * nazwa dostawcy nie jest mu pokazywana.
 */
class ExternalServer extends Model
{
    use SoftDeletes;

    public const PENDING = 'pending';       // zamówiony, jeszcze nie wysłany do dostawcy

    public const BUILDING = 'building';

    public const RUNNING = 'running';

    public const STOPPED = 'stopped';

    public const BUSY = 'busy';

    public const ERROR = 'error';

    public const DELETED = 'deleted';

    /** Stany, które panel odpytuje co minutę. */
    public const TRANSITIONAL = [self::PENDING, self::BUILDING, self::BUSY];

    protected $fillable = [
        'user_id', 'provider_account_id', 'remote_id', 'name', 'hostname', 'location', 'plan',
        'cpu', 'ram_mb', 'disk_gb', 'image', 'image_name', 'status', 'ipv4', 'ipv6', 'password',
        'last_error', 'suspended_at', 'synced_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'cpu' => 'integer',
            'ram_mb' => 'integer',
            'disk_gb' => 'integer',
            'suspended_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $server) {
            $server->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ProviderAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'provider_account_id');
    }

    /** @return HasOne<BillingService, $this> */
    public function billingService(): HasOne
    {
        return $this->hasOne(BillingService::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function statusLabel(): string
    {
        if ($this->isSuspended()) {
            return __('zawieszony');
        }

        return match ($this->status) {
            self::PENDING, self::BUILDING => __('tworzenie'),
            self::RUNNING => __('działa'),
            self::STOPPED => __('zatrzymany'),
            self::BUSY => __('trwa operacja'),
            self::ERROR => __('błąd'),
            self::DELETED => __('usunięty'),
            default => $this->status,
        };
    }

    public function statusTone(): string
    {
        if ($this->isSuspended()) {
            return 'warning';
        }

        return match ($this->status) {
            self::RUNNING => 'ok',
            self::STOPPED => 'neutral',
            self::ERROR, self::DELETED => 'critical',
            default => 'info',
        };
    }
}
