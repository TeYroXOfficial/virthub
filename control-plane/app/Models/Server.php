<?php

namespace App\Models;

use App\Enums\ServerState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Server extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'hypervisor_id',
        'vps_package_id',
        'os_template_id',
        'virtualization',
        'hostname',
        'label',
        'vcpu',
        'ram_mb',
        'disk_gb',
        'bandwidth_gb',
        'billing_reference',
    ];

    protected $hidden = ['vnc_password', 'root_password'];

    protected function casts(): array
    {
        return [
            'state' => ServerState::class,
            'virtualization' => \App\Enums\Virtualization::class,
            // Hasło root jest jednorazowe: pokazujemy je klientowi raz po
            // provisioningu i kasujemy przy pierwszym odczycie.
            'root_password' => 'encrypted',
            'vnc_password' => 'encrypted',
            'suspended_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'firewall_enabled' => 'boolean',
            'firewall_locked' => 'boolean',
            'boot_from_iso' => 'boolean',
            'guest_os_checked_at' => 'datetime',
            'traffic_blocked_at' => 'datetime',
        ];
    }

    // --- relacje ------------------------------------------------------------

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

    /** @return BelongsTo<VpsPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(VpsPackage::class, 'vps_package_id');
    }

    /** @return BelongsTo<OsTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(OsTemplate::class, 'os_template_id');
    }

    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /** @return HasMany<ServerJob, $this> */
    public function jobs(): HasMany
    {
        return $this->hasMany(ServerJob::class)->latest();
    }

    /** @return HasMany<Backup, $this> */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /** @return HasMany<FirewallRule, $this> */
    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<IsoImage, $this> */
    public function iso(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IsoImage::class, 'iso_image_id');
    }

    public function firewallRules(): HasMany
    {
        // Reguły administratora zawsze przed regułami klienta — tak też
        // trafiają do nftables, więc klient nie może ich obejść.
        return $this->hasMany(FirewallRule::class)
            ->orderByRaw("CASE WHEN managed_by = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('position')
            ->orderBy('id');
    }

    /** @return HasMany<ServerMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    // --- stan ---------------------------------------------------------------

    public function primaryIp(): ?IpAddress
    {
        return $this->ipAddresses->firstWhere('is_primary', true)
            ?? $this->ipAddresses->first();
    }

    public function isContainer(): bool
    {
        return $this->virtualization === \App\Enums\Virtualization::Lxc;
    }

    public function isRunning(): bool
    {
        return $this->state === ServerState::Running;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Czy maszyna przyjmie teraz polecenie od klienta.
     * Zawieszony VPS odmawia wszystkiego poza odwieszeniem przez administratora.
     */
    public function acceptsCommands(): bool
    {
        return ! $this->isSuspended() && $this->state->acceptsCommands();
    }

    public function markState(ServerState $state, ?string $message = null): void
    {
        $this->forceFill([
            'state' => $state,
            'state_message' => $message,
        ])->save();
    }

    /**
     * Hasło root pokazujemy dokładnie raz. Kolejne wejście na stronę maszyny
     * już go nie zobaczy — klient ma je zapisać albo zresetować.
     *
     * Wyjątek: dopóki maszyna się tworzy (albo reinstaluje), hasło zostaje w bazie. Zadanie
     * provisioningu czyta je stąd i wysyła do agenta, a klient trafia na
     * stronę maszyny zaraz po zamówieniu — skasowanie hasła w tym momencie
     * dałoby maszynę bez hasła i bez możliwości zalogowania.
     */
    /** Rodzina systemu do ikony: odczytana z maszyny, a bez odczytu — z szablonu. */
    public function osFamily(): ?string
    {
        return \App\Domain\Provisioning\GuestOs::familyFor($this->guest_os_id) ?? $this->template?->family;
    }

    /** Nazwa systemu dla ludzi: „Ubuntu 24.04.1 LTS" z maszyny albo nazwa szablonu. */
    public function osLabel(): ?string
    {
        return $this->guest_os_name ?? $this->template?->name;
    }

    public function osDetected(): bool
    {
        return $this->guest_os_name !== null;
    }

    public function consumeRootPassword(): ?string
    {
        $password = $this->root_password;

        if ($password !== null && ! in_array($this->state, [ServerState::Building, ServerState::Rebuilding], true)) {
            $this->forceFill(['root_password' => null])->save();
        }

        return $password;
    }

    // --- zakresy ------------------------------------------------------------

    /** @param Builder<Server> $query */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        if (! $user->isStaff()) {
            $query->where('user_id', $user->id);
        }
    }

    /** @param Builder<Server> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotIn('state', [ServerState::Deleting->value]);
    }
}
