<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Harmonogram kopii: cel, częstotliwość, godzina i liczba przechowywanych kopii. */
class BackupPlan extends Model
{
    protected $fillable = ['name', 'backup_target_id', 'interval_hours', 'hour', 'retention', 'compress', 'is_active', 'incremental', 'full_every'];

    protected $attributes = ['interval_hours' => 24, 'hour' => 3, 'retention' => 7, 'compress' => true, 'is_active' => true];

    protected function casts(): array
    {
        return ['interval_hours' => 'integer', 'hour' => 'integer', 'retention' => 'integer', 'compress' => 'boolean', 'is_active' => 'boolean',
            'incremental' => 'boolean', 'full_every' => 'integer'];
    }

    /** @return BelongsTo<BackupTarget, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class, 'backup_target_id');
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
