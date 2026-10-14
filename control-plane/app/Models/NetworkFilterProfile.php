<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Profil filtrowania sieci (administrator): anty-spoofing, blokada RA IPv6
 * i serwera DHCP oraz reguły, których klient nie zmieni. Agent stosuje go
 * przed zaporą klienta.
 */
class NetworkFilterProfile extends Model
{
    protected $fillable = ['name', 'description', 'anti_spoofing', 'block_router_advertisements', 'block_dhcp_server', 'rules', 'is_default'];

    protected $attributes = ['anti_spoofing' => true, 'block_router_advertisements' => true, 'block_dhcp_server' => true, 'is_default' => false];

    protected function casts(): array
    {
        return [
            'anti_spoofing' => 'boolean',
            'block_router_advertisements' => 'boolean',
            'block_dhcp_server' => 'boolean',
            'is_default' => 'boolean',
            'rules' => 'array',
        ];
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public static function defaultProfile(): ?self
    {
        return self::query()->where('is_default', true)->first();
    }

    /** @return array<string, mixed> ładunek NetworkFilter agenta */
    public function toAgentPayload(): array
    {
        return [
            'anti_spoofing' => $this->anti_spoofing,
            'block_router_advertisements' => $this->block_router_advertisements,
            'block_dhcp_server' => $this->block_dhcp_server,
            'rules' => array_values($this->rules ?? []),
        ];
    }
}
