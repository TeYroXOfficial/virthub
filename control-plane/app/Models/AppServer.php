<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Aplikacja klienta: serwer gry, bot albo inna usługa w kontenerze na węźle. */
class AppServer extends Model
{
    public const STATUS_INSTALLING = 'installing';

    public const STATUS_INSTALL_FAILED = 'install_failed';

    public const STATUS_READY = 'ready';

    public const STATUS_DELETING = 'deleting';

    protected $fillable = [
        'uuid', 'user_id', 'hypervisor_id', 'app_egg_id', 'app_plan_id', 'name', 'docker_image',
        'environment', 'memory_mb', 'cpu_percent', 'disk_mb', 'status', 'status_message',
        'installed_at', 'suspended_at', 'suspension_reason',
    ];

    protected $attributes = ['status' => self::STATUS_INSTALLING, 'cpu_percent' => 0];

    protected function casts(): array
    {
        return [
            'environment' => 'array',
            'memory_mb' => 'integer',
            'cpu_percent' => 'integer',
            'disk_mb' => 'integer',
            'installed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'abuse_detected_at' => 'datetime',
            'abuse_findings' => 'array',
            'abuse_exempt' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AppServer $app) {
            $app->uuid ??= (string) Str::uuid();
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

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    /** @return BelongsTo<AppEgg, $this> */
    public function egg(): BelongsTo
    {
        return $this->belongsTo(AppEgg::class, 'app_egg_id');
    }

    /** @return BelongsTo<AppPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(AppPlan::class, 'app_plan_id');
    }

    /** @return HasMany<AppAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(AppAllocation::class)->orderByDesc('is_primary')->orderBy('port');
    }

    /** @return HasMany<AppJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(AppJob::class)->latest('id');
    }

    public function primaryAllocation(): ?AppAllocation
    {
        return $this->allocations->first();
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isInstalling(): bool
    {
        return $this->status === self::STATUS_INSTALLING;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /** Czy klient może sterować aplikacją (zasilanie, konsola, pliki). */
    public function acceptsCommands(): bool
    {
        return $this->isReady() && ! $this->isSuspended() && $this->hypervisor !== null;
    }

    /** Login SFTP: identyfikator użytkownika panelu i początek UUID aplikacji (jak w Wings). */
    public function sftpUsername(User $user): string
    {
        return 'u'.$user->id.'.'.substr($this->uuid, 0, 8);
    }

    /** Adres do połączenia: publiczny adres węzła i główny port. */
    public function address(): ?string
    {
        $port = $this->primaryAllocation()?->port;

        return $port ? ($this->hypervisor?->publicAddress() ?? '?').':'.$port : null;
    }

    public function statusLabel(): string
    {
        if ($this->isSuspended()) {
            return __('zawieszona');
        }

        return match ($this->status) {
            self::STATUS_INSTALLING => __('instalacja'),
            self::STATUS_INSTALL_FAILED => __('błąd instalacji'),
            self::STATUS_DELETING => __('usuwanie'),
            default => __('gotowa'),
        };
    }

    /** Wartości zmiennych: ustawione przez klienta, a bez tego — domyślne z eggu. */
    public function variableValues(): array
    {
        $values = [];
        foreach ($this->egg?->variableList() ?? [] as $var) {
            $env = $var['env_variable'] ?? null;
            if (! is_string($env) || $env === '') {
                continue;
            }
            $values[$env] = (string) ($this->environment[$env] ?? $var['default_value'] ?? '');
        }

        return $values;
    }
}
