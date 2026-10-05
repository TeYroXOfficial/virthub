<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Wpis księgi portfela — kwota ze znakiem i saldo po operacji. */
class WalletTransaction extends Model
{
    protected $fillable = ['user_id', 'type', 'amount', 'balance_after', 'description', 'invoice_id', 'billing_service_id', 'actor_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'topup' => __('doładowanie'),
            'payment' => __('zapłata faktury'),
            'usage' => __('opłata za usługę'),
            'refund' => __('zwrot'),
            'adjustment' => __('korekta'),
            default => $this->type,
        };
    }
}
