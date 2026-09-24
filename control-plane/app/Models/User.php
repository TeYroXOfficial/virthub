<?php

namespace App\Models;

use App\Domain\Access\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPPORT = 'support';
    public const ROLE_CUSTOMER = 'customer';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'billing_reference',
        'permissions',
        'max_servers',
        'allowed_package_ids',
    ];

    /** Domyślna rola także w pamięci — przed ponownym odczytem z bazy. */
    protected $attributes = [
        'role' => self::ROLE_CUSTOMER,
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'suspended_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'permissions' => 'array',
            'allowed_package_ids' => 'array',
            'max_servers' => 'integer',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Support widzi cudze maszyny i może wykonać akcję serwisową, ale nie
     * zmienia pakietów ani nie usuwa danych klienta.
     */
    public function isStaff(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPPORT], true);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** @return list<string> */
    public function effectivePermissions(): array
    {
        if ($this->isAdmin()) {
            return Permissions::all();
        }

        return $this->permissions === null
            ? Permissions::defaultsFor($this->role ?? self::ROLE_CUSTOMER)
            : Permissions::sanitize($this->role ?? self::ROLE_CUSTOMER, $this->permissions);
    }

    /**
     * Czy użytkownik ma uprawnienie. Zawieszone konto nie ma żadnych;
     * administrator — wszystkie. Nazwa inna niż can(), bo to metoda Gate.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        return $this->isAdmin() || in_array($permission, $this->effectivePermissions(), true);
    }

    /** Personel z dostępem do choć jednego działu administracji. */
    public function hasAnyAdminPermission(): bool
    {
        return $this->isStaff() && collect($this->effectivePermissions())
            ->contains(fn (string $p) => str_starts_with($p, 'admin.'));
    }

    public function serverLimit(): int
    {
        return $this->max_servers ?? (int) config('virthub.limits.servers_per_customer');
    }

    public function mayOrderPackage(VpsPackage $package): bool
    {
        return $this->allowed_package_ids === null
            || in_array($package->id, array_map('intval', $this->allowed_package_ids), true);
    }
}
