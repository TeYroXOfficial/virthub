<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ślad po operacji zleconej agentowi. Rekord powstaje w chwili zlecenia i żyje
 * niezależnie od kolejki — po awarii wiadomo, co i kiedy zostało zlecone.
 */
class ServerJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'server_id',
        'hypervisor_id',
        'user_id',
        'action',
        'status',
        'agent_job_id',
        'payload',
        'result',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }

    public function markDone(?array $result = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_DONE,
            'result' => $result,
            'finished_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => $error,
            'finished_at' => now(),
        ])->save();
    }
}
