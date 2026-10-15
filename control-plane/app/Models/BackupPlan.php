<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Harmonogram kopii: cel, częstotliwość, godzina i liczba przechowywanych kopii. */
class BackupPlan extends Model
{
    protected $fillable = ['name', 'backup_target_id', 'interval_hours', 'hour', 'retention', 'compress', 'is_active', 'incremental', 'full_every',
        'mode', 'keep_daily', 'keep_weekly', 'keep_monthly', 'days'];

    public const MODES = ['full', 'incremental', 'differential'];

    /** Tryb kopii: pełna, przyrostowa (od poprzedniej) albo różnicowa (od ostatniej pełnej). */
    public function backupMode(): string
    {
        return in_array($this->mode, self::MODES, true) ? $this->mode : ($this->incremental ? 'incremental' : 'full');
    }

    /** Czy plan wolno uruchomić w dniu tygodnia (0 = niedziela … 6 = sobota). */
    public function runsOn(int $weekday): bool
    {
        return empty($this->days) || in_array($weekday, array_map('intval', (array) $this->days), true);
    }

    protected $attributes = ['interval_hours' => 24, 'hour' => 3, 'retention' => 7, 'compress' => true, 'is_active' => true];

    protected function casts(): array
    {
        return ['interval_hours' => 'integer', 'hour' => 'integer', 'retention' => 'integer', 'compress' => 'boolean', 'is_active' => 'boolean',
            'incremental' => 'boolean', 'full_every' => 'integer', 'days' => 'array',
            'keep_daily' => 'integer', 'keep_weekly' => 'integer', 'keep_monthly' => 'integer'];
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
