<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Długa operacja na aplikacji wykonywana w kolejce agenta (instalacja, reinstalacja). */
class AppJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['app_server_id', 'user_id', 'action', 'status', 'agent_job_id', 'error', 'finished_at'];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    /** @return BelongsTo<AppServer, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(AppServer::class, 'app_server_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }
}
