<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Klucz dostępu (passkey) albo klucz sprzętowy FIDO2 zarejestrowany przez WebAuthn. */
class WebauthnCredential extends Model
{
    public const MAX_PER_USER = 10;

    protected $fillable = ['name', 'credential_id', 'public_key', 'sign_count', 'transports', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['sign_count' => 'integer', 'last_used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
