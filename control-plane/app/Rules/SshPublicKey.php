<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Klucz publiczny OpenSSH w jednej linii: typ, klucz base64, opcjonalny
 * komentarz. Klucz trafia do konfiguracji cloud-init (YAML) — znak nowej
 * linii pozwoliłby dopisać do niej dowolne polecenia, więc cała wartość
 * musi pasować, nie tylko jej początek.
 */
class SshPublicKey implements ValidationRule
{
    public const PATTERN = '/\A(ssh-(rsa|ed25519|dss)|ecdsa-sha2-nistp(256|384|521)|sk-(ssh-ed25519|ecdsa-sha2-nistp256)@openssh\.com) [A-Za-z0-9+\/]+={0,3}( [^\x00-\x1f\x7f]{1,200})?\z/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, trim($value))) {
            $fail('Klucz SSH musi być jedną linią w formacie OpenSSH (ssh-ed25519 AAAA… komentarz).');
        }
    }
}
