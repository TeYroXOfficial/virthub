<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['user_id', 'invoice_id', 'gateway', 'reference', 'amount', 'currency', 'meta'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'meta' => 'array'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public static function gatewayLabel(string $gateway): string
    {
        return match ($gateway) {
            'wallet' => __('Portfel'),
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
            'manual' => __('Ręcznie (przelew)'),
            'free' => __('Bezpłatnie'),
            default => $gateway,
        };
    }
}
