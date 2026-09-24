<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'sampled_at',
        'cpu_percent',
        'cpu_time_ns',
        'ram_used_mb',
        'ram_total_mb',
        'disk_read_bps',
        'disk_write_bps',
        'net_rx_bps',
        'net_tx_bps',
        'disk_read_bytes',
        'disk_write_bytes',
        'net_rx_bytes',
        'net_tx_bytes',
    ];

    protected function casts(): array
    {
        return ['sampled_at' => 'datetime'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
