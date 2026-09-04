<?php

namespace App\Models;

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
}
