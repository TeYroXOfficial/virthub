<?php

namespace App\Domain\Security;

use Illuminate\Http\Request;

/**
 * Ponowne potwierdzenie tożsamości (step-up) przed operacjami wrażliwymi:
 * usunięcie i reinstalacja maszyny, tokeny API, klucze dostępu, zarządzanie
 * kontami. Potwierdzenie (hasło, kod 2FA albo klucz dostępu) ważne kilka minut.
 */
class StepUp
{
    private const SESSION = 'auth.confirmed_at';

    public static function minutes(): int
    {
        return max(1, (int) config('virthub.step_up_minutes', 15));
    }

    public static function confirm(Request $request): void
    {
        $request->session()->put(self::SESSION, now()->getTimestamp());
    }

    public static function fresh(Request $request): bool
    {
        $at = (int) $request->session()->get(self::SESSION, 0);

        return $at > 0 && now()->getTimestamp() - $at <= self::minutes() * 60;
    }
}
