<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Dziennik działań. Zapisujemy też etykietę aktora, bo po usunięciu konta
 * `actor_id` zostaje wyzerowane, a wpis musi dalej mówić, kto wykonał operację.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'actor_id',
        'actor_label',
        'action',
        'subject_type',
        'subject_id',
        'meta',
        'ip_address',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(
        string $action,
        ?Model $subject = null,
        array $meta = [],
        ?User $actor = null,
    ): self {
        $actor ??= Auth::user();

        return self::create([
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->email ?? 'system',
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'ip_address' => Request::ip(),
        ]);
    }
}
