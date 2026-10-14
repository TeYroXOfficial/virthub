<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dodatkowa karta sieciowa maszyny (slot 1–7) w sieci prywatnej. */
class ServerNic extends Model
{
    protected $fillable = ['server_id', 'private_network_id', 'slot', 'address', 'mac'];

    protected function casts(): array
    {
        return ['slot' => 'integer'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<PrivateNetwork, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(PrivateNetwork::class, 'private_network_id');
    }

    /** MAC, który nada agent, gdy panel nie ma własnego (52:54:<slot>:…). */
    public function effectiveMac(): string
    {
        $id = $this->server_id;

        return $this->mac ?? sprintf('52:54:%02x:%02x:%02x:%02x', $this->slot, ($id >> 16) & 0xFF, ($id >> 8) & 0xFF, $id & 0xFF);
    }

    /** @return array<string, mixed> */
    public function toAgentPayload(): array
    {
        return array_filter([
            'slot' => $this->slot,
            'network_id' => $this->private_network_id,
            'mac' => $this->mac,
            'address' => $this->address,
            'prefix' => $this->address ? $this->network->prefix() : null,
        ], fn ($v) => $v !== null);
    }
}
