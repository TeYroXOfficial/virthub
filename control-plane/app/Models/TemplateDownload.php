<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stan pobrania szablonu kontenera na konkretnym węźle.
 */
class TemplateDownload extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'os_template_id',
        'hypervisor_id',
        'status',
        'agent_job_id',
        'error',
    ];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    /** @return BelongsTo<OsTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(OsTemplate::class, 'os_template_id');
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

    public function label(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED => 'w kolejce',
            self::STATUS_DOWNLOADING => 'pobieranie',
            self::STATUS_READY => 'gotowy',
            self::STATUS_FAILED => 'błąd',
            default => $this->status,
        };
    }

    public function tone(): string
    {
        return match ($this->status) {
            self::STATUS_READY => 'ok',
            self::STATUS_FAILED => 'critical',
            default => 'warning',
        };
    }
}
