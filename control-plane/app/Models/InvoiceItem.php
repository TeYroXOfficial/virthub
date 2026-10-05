<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $fillable = ['invoice_id', 'billing_service_id', 'kind', 'description', 'amount', 'period_start', 'period_end'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'period_start' => 'datetime', 'period_end' => 'datetime'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<BillingService, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(BillingService::class, 'billing_service_id');
    }
}
