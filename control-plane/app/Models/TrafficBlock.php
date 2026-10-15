<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dokupiony transfer na okres rozliczeniowy maszyny (powiększa jej limit w tym okresie). */
class TrafficBlock extends Model
{
    protected $fillable = ['server_id', 'period_start', 'gb', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'gb' => 'integer'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
