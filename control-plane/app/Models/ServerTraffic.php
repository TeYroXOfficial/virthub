<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Transfer maszyny w jednym okresie rozliczeniowym (miesiąc kalendarzowy). */
class ServerTraffic extends Model
{
    protected $table = 'server_traffic';

    protected $fillable = ['server_id', 'period_start', 'rx_bytes', 'tx_bytes'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'rx_bytes' => 'integer', 'tx_bytes' => 'integer'];
    }
}
