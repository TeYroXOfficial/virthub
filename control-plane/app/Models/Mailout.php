<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Masowa wiadomość e-mail do wybranej grupy użytkowników. */
class Mailout extends Model
{
    public const DRAFT = 'draft';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['subject', 'body', 'audience', 'respect_opt_out', 'created_by'];

    protected $attributes = ['status' => self::DRAFT, 'respect_opt_out' => true];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'respect_opt_out' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<MailoutRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(MailoutRecipient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editable(): bool
    {
        return $this->status === self::DRAFT;
    }
}
