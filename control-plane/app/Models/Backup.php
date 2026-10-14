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
        'backup_target_id',
        'remote_key',
        'sha256',
        'size_bytes',
        'parent_id',
        'kind',
        'checkpoint',
    ];

    public const KIND_FULL = 'full';

    public const KIND_INCREMENTAL = 'incremental';

    public function isIncremental(): bool
    {
        return $this->kind === self::KIND_INCREMENTAL;
    }

    /** @return BelongsTo<Backup, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Backup, $this> */
    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Wcześniejsze ogniwa łańcucha — od kopii pełnej do bezpośredniego rodzica.
     *
     * @return list<Backup>
     */
    public function ancestors(): array
    {
        $chain = [];
        $node = $this;
        $guard = 0;
        while ($node->isIncremental() && $node->parent_id !== null && $guard++ < 500) {
            $node = self::query()->find($node->parent_id);
            if ($node === null) {
                break;
            }
            array_unshift($chain, $node);
        }

        return $chain;
    }

    /** @return list<array{key: string, sha256: ?string}> */
    public function chainPayload(): array
    {
        return array_map(fn (Backup $b) => array_filter(['key' => (string) $b->remote_key, 'sha256' => $b->sha256]), $this->ancestors());
    }

    /** Łańcuch kompletny (pełna na początku, wszystkie ogniwa gotowe) — da się przywrócić. */
    public function chainComplete(): bool
    {
        if (! $this->isIncremental()) {
            return true;
        }
        $chain = $this->ancestors();

        return $chain !== [] && ! $chain[0]->isIncremental()
            && collect($chain)->every(fn (Backup $b) => $b->status === self::STATUS_READY);
    }

    public const TYPE_REMOTE = 'remote';

    /** @return BelongsTo<BackupTarget, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class, 'backup_target_id');
    }

    public function isRemote(): bool
    {
        return $this->type === self::TYPE_REMOTE;
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isRestorable(): bool
    {
        return $this->status === self::STATUS_READY && $this->chainComplete();
    }
}
