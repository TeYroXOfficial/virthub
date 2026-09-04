<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    use HasFactory;

    public const STATUS_CREATING = 'creating';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RESTORING = 'restoring';

    protected $fillable = [
        'server_id',
        'name',
        'type',
        'status',
        'storage_path',
        'size_mb',
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isRestorable(): bool
    {
        return $this->status === self::STATUS_READY;
    }
}
