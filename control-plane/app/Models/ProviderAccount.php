<?php

namespace App\Models;

use App\Domain\External\ProviderRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/** Konto u dostawcy zewnętrznego (np. token API Onidel) — dane szyfrowane APP_KEY. */
class ProviderAccount extends Model
{
    protected $fillable = ['driver', 'name', 'is_active'];

    protected $hidden = ['credentials'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return array<string, string> */
    public function credentials(): array
    {
        try {
            $data = json_decode(Crypt::decryptString((string) ($this->attributes['credentials'] ?? '')), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /** @param  array<string, string>  $credentials */
    public function setCredentials(array $credentials): void
    {
        $this->attributes['credentials'] = Crypt::encryptString(json_encode($credentials, JSON_UNESCAPED_SLASHES));
    }

    public function driverName(): string
    {
        return app(ProviderRegistry::class)->name($this->driver);
    }

    public function driverAvailable(): bool
    {
        return app(ProviderRegistry::class)->has($this->driver);
    }

    /** @return HasMany<ExternalServer, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(ExternalServer::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
