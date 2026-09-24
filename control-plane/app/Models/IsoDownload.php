<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IsoDownload extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = ['iso_image_id', 'hypervisor_id', 'status', 'agent_job_id', 'error', 'finished_at'];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    /** @return BelongsTo<IsoImage, $this> */
    public function iso(): BelongsTo
    {
        return $this->belongsTo(IsoImage::class, 'iso_image_id');
    }

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_DOWNLOADING], true);
    }
}
