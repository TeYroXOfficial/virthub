<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Otwarty albo zamknięty alert dla jednej maszyny lub hypervisora. */
class AlertIncident extends Model
{
    protected $fillable = ['alert_rule_id', 'subject_type', 'subject_id', 'subject_label', 'value', 'peak', 'started_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'peak' => 'float',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
