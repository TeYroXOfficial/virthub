<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zgłoszenie klienta. Status zmienia się sam przy odpowiedziach:
 * odpowiedź personelu → answered, odpowiedź klienta → customer_reply.
 */
class Ticket extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_CUSTOMER_REPLY = 'customer_reply';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_ANSWERED, self::STATUS_CUSTOMER_REPLY, self::STATUS_ON_HOLD, self::STATUS_CLOSED];

    /** Czekają na personel. */
    public const AWAITING_STAFF = [self::STATUS_OPEN, self::STATUS_CUSTOMER_REPLY];

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected $fillable = [
        'user_id', 'ticket_department_id', 'subject', 'status', 'priority', 'assigned_to',
        'service_type', 'service_id', 'last_reply_at', 'last_reply_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['last_reply_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<TicketDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class, 'ticket_department_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<TicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    /** Maszyna albo aplikacja, której dotyczy zgłoszenie (może już nie istnieć). */
    public function service(): Server|AppServer|null
    {
        return match ($this->service_type) {
            'server' => Server::query()->find($this->service_id),
            'app' => AppServer::query()->find($this->service_id),
            default => null,
        };
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => __('otwarte'),
            self::STATUS_ANSWERED => __('odpowiedziano'),
            self::STATUS_CUSTOMER_REPLY => __('odpowiedź klienta'),
            self::STATUS_ON_HOLD => __('wstrzymane'),
            self::STATUS_CLOSED => __('zamknięte'),
            default => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN, self::STATUS_CUSTOMER_REPLY => 'warning',
            self::STATUS_ANSWERED => 'ok',
            self::STATUS_ON_HOLD => 'info',
            default => 'neutral',
        };
    }

    public static function priorityLabel(string $priority): string
    {
        return match ($priority) {
            'low' => __('niski'),
            'medium' => __('średni'),
            'high' => __('wysoki'),
            'urgent' => __('pilny'),
            default => $priority,
        };
    }

    public function priorityTone(): string
    {
        return match ($this->priority) {
            'urgent' => 'critical',
            'high' => 'warning',
            default => 'neutral',
        };
    }
}
