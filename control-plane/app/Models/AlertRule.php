<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Reguła alertu: metryka, próg i czas trwania, zakres (węzeł) i kanały powiadomień. */
class AlertRule extends Model
{
    protected $fillable = ['name', 'metric', 'threshold', 'duration_minutes', 'hypervisor_id', 'notify_email', 'notify_webhook', 'is_active'];

    protected $attributes = ['threshold' => 90, 'duration_minutes' => 5, 'notify_email' => true, 'notify_webhook' => true, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'duration_minutes' => 'integer',
            'notify_email' => 'boolean',
            'notify_webhook' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    /** @return HasMany<AlertIncident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(AlertIncident::class);
    }
}
