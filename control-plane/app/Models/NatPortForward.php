<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Port z bloku NAT maszyny skierowany przez klienta na wybraną usługę w maszynie. */
class NatPortForward extends Model
{
    protected $fillable = ['server_id', 'ip_address_id', 'external_port', 'internal_port', 'label'];

    protected function casts(): array
    {
        return [
            'external_port' => 'integer',
            'internal_port' => 'integer',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<IpAddress, $this> */
    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class);
    }
}
