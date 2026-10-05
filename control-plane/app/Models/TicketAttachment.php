<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Plik dołączony do wiadomości — na prywatnym dysku, wydawany tylko uprawnionym. */
class TicketAttachment extends Model
{
    protected $fillable = ['ticket_message_id', 'path', 'original_name', 'mime', 'size'];

    /** @return BelongsTo<TicketMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }
}
