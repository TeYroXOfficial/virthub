<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zapisany fragment tekstu do wpisania w konsoli VNC (polecenie, konfiguracja,
 * hasło). Treść szyfrowana w bazie — fragmenty bywają hasłami.
 */
class ConsoleSnippet extends Model
{
    public const MAX_PER_USER = 50;

    public const MAX_LENGTH = 4000;

    protected $fillable = ['name', 'body'];

    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
