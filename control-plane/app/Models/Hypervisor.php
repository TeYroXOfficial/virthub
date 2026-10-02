<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'hypervisor_group_id',
        'name',
        'hostname',
        'public_address',
        'agent_url',
        'agent_token',
        'callback_secret',
        'status',
        'virtualization',
        'cpu_cores_total',
        'ram_mb_total',
        'disk_gb_total',
        'accepts_new_servers',
        'apps_enabled',
        'app_port_start',
        'app_port_end',
        'app_memory_overcommit',
        'bridge',
        'max_servers',
        'notes',
        'cpu_model',
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
            'apps_enabled' => 'boolean',
            'app_port_start' => 'integer',
            'app_port_end' => 'integer',
            'app_memory_overcommit' => 'integer',
            'virtualization' => \App\Enums\Virtualization::class,
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

    /** @return BelongsTo<HypervisorGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(HypervisorGroup::class, 'hypervisor_group_id');
    }

    /** @return HasMany<TemplateDownload, $this> */
    public function templateDownloads(): HasMany
    {
        return $this->hasMany(TemplateDownload::class);
    }

    public function runsContainers(): bool
    {
        return $this->virtualization === \App\Enums\Virtualization::Lxc;
    }

    /**
     * Pule przypisane bezpośrednio do węzła (bez pul jego grupy).
     *
     * @return HasMany<IpPool, $this>
     */
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

    /**
     * Adres, pod którym klienci łączą się z portami NAT maszyn na tym węźle.
     *
     * Kolejność: ustawiony przez administratora → publiczny adres wyjścia
     * zgłoszony przez agenta → host z adresu agenta → adres wyjścia, nawet
     * prywatny (węzeł za NAT-em dostawcy) → nazwa hosta. Sama nazwa hosta
     * (np. „node1") zwykle nie rozwiązuje się w DNS klienta, więc jest
     * ostatnią deską ratunku.
     */
    public function publicAddress(): ?string
    {
        $reported = $this->last_health['public_ipv4'] ?? null;
        $reported = is_string($reported) && filter_var($reported, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $reported : null;
        $isPublic = fn (?string $ip) => $ip !== null
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

        $agentHost = $this->agent_url ? parse_url($this->agent_url, PHP_URL_HOST) : null;
        $agentHost = is_string($agentHost) ? trim($agentHost, '[]') : null;
        $agentUsable = $agentHost !== null && $agentHost !== 'localhost'
            && (! filter_var($agentHost, FILTER_VALIDATE_IP) || $isPublic($agentHost));

        return $this->public_address
            ?: ($isPublic($reported) ? $reported : null)
            ?: ($agentUsable ? $agentHost : null)
            ?: $reported
            ?: $this->hostname;
    }

    /** @return HasMany<AppServer, $this> */
    public function appServers(): HasMany
    {
        return $this->hasMany(AppServer::class);
    }

    /** Czy węzeł przyjmuje aplikacje: włączone przez administratora, Docker działa, jest pula portów. */
    public function acceptsApps(): bool
    {
        return $this->apps_enabled
            && $this->app_port_start && $this->app_port_end
            && ($this->last_health['apps']['available'] ?? false);
    }

    /** Procesor pokazywany klientom: ustawiony przez administratora albo wykryty przez agenta. */
    /** Audyt ochrony hosta z ostatniego raportu agenta (null — agent bez tej funkcji). */
    public function securityReport(): ?array
    {
        $report = $this->last_health['host_security'] ?? null;

        return is_array($report) ? $report : null;
    }

    public function cpuModel(): ?string
    {
        return $this->cpu_model ?: ($this->last_health['cpu_model'] ?? null);
    }

    /**
     * Limity administracyjne poza pojemnością: maksymalna liczba maszyn na
     * węźle i wstrzymane przyjmowanie maszyn przez całą grupę.
     */
    public function acceptsMoreServers(): bool
    {
        if ($this->group !== null && ! $this->group->accepts_new_servers) {
            return false;
        }

        return $this->max_servers === null || $this->servers()->count() < $this->max_servers;
    }

    /** @param Builder<Hypervisor> $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('status', self::STATUS_ONLINE)
            ->where('accepts_new_servers', true)
            ->where('last_seen_at', '>=', now()->subSeconds(self::HEARTBEAT_TIMEOUT));
    }
}
