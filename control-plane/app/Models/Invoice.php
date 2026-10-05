<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUNDED = 'refunded';

    public const TYPE_SERVICE = 'service';

    public const TYPE_TOPUP = 'topup';

    protected $fillable = [
        'user_id', 'number', 'type', 'status', 'currency', 'tax_rate', 'subtotal', 'tax', 'total',
        'due_at', 'paid_at', 'reminded_at', 'overdue_notified_at', 'seller', 'buyer', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'tax_rate' => 'integer',
            'subtotal' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'reminded_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'seller' => 'array',
            'buyer' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isUnpaid(): bool
    {
        return $this->status === self::STATUS_UNPAID;
    }

    public function isOverdue(): bool
    {
        return $this->isUnpaid() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_UNPAID => $this->isOverdue() ? __('po terminie') : __('do zapłaty'),
            self::STATUS_PAID => __('opłacona'),
            self::STATUS_CANCELLED => __('anulowana'),
            self::STATUS_REFUNDED => __('zwrócona'),
            default => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_UNPAID => $this->isOverdue() ? 'critical' : 'warning',
            self::STATUS_PAID => 'ok',
            default => 'neutral',
        };
    }
}
