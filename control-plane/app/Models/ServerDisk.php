<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dodatkowy dysk maszyny KVM: slot 1–7 → vdb–vdh (vda to dysk główny z servers.disk_gb). */
class ServerDisk extends Model
{
    public const MAX_SLOTS = 7;

    protected $fillable = ['server_id', 'slot', 'size_gb'];

    protected function casts(): array
    {
        return ['slot' => 'integer', 'size_gb' => 'integer'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function dev(): string
    {
        return 'vd'.'abcdefgh'[$this->slot];
    }
}
