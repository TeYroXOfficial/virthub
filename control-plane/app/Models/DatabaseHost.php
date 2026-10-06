<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/** Serwer MySQL/MariaDB, na którym panel zakłada bazy aplikacji. */
class DatabaseHost extends Model
{
    protected $fillable = ['name', 'host', 'port', 'public_host', 'username', 'password', 'hypervisor_id', 'max_databases', 'is_active'];

    protected $hidden = ['password'];

    protected $attributes = ['port' => 3306, 'is_active' => true];

    protected function casts(): array
    {
        return ['port' => 'integer', 'max_databases' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    /** @return HasMany<AppDatabase, $this> */
    public function databases(): HasMany
    {
        return $this->hasMany(AppDatabase::class);
    }

    public function setSecret(string $password): void
    {
        $this->password = Crypt::encryptString($password);
    }

    public function secret(): string
    {
        return Crypt::decryptString($this->password);
    }

    /** Adres, który widzi klient (np. publiczny IP węzła). */
    public function clientHost(): string
    {
        return $this->public_host ?: $this->host;
    }
}
