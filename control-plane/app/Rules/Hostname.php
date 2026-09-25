<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Nazwa hosta (FQDN) — trafia do cloud-init i konfiguracji systemu gościa. */
class Hostname implements ValidationRule
{
    public const PATTERN = '/\A(?=.{1,253}\z)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\z/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, $value)) {
            $fail('Nazwa hosta musi być poprawną nazwą domenową, np. vps1.mojadomena.pl.');
        }
    }
}
