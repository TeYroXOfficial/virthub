<?php

namespace App\Domain\Access;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * „Zaloguj jako” — administrator widzi panel oczami klienta (pomoc, zgłoszenia).
 * Sesja pamięta, kto naprawdę jest zalogowany; dziennik zdarzeń to odnotowuje,
 * a zmiany zabezpieczeń konta klienta są w tym czasie zablokowane.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public const RETURN_KEY = 'impersonator_return';

    public static function allowed(?User $admin, User $target): bool
    {
        return $admin !== null && $admin->isAdmin() && ! $target->isAdmin()
            && $admin->id !== $target->id && ! $target->isSuspended() && ! self::active();
    }

    public static function active(): bool
    {
        $request = request();

        return $request->hasSession() && $request->session()->has(self::SESSION_KEY);
    }

    public static function impersonator(): ?User
    {
        return self::active() ? User::query()->find(request()->session()->get(self::SESSION_KEY)) : null;
    }

    public function start(Request $request, User $admin, User $target, ?string $returnTo = null): void
    {
        if (! self::allowed($admin, $target)) {
            throw new \DomainException(__('Nie możesz zalogować się na to konto.'));
        }
        AuditLog::record('user.impersonate', $target, ['email' => $target->email], $admin);

        Auth::guard('web')->login($target);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $admin->id);
        $request->session()->put(self::RETURN_KEY, $returnTo ?: route('panel.admin.users.edit', $target));
    }

    /** Powrót na konto administratora; zwraca adres, z którego zaczęto. */
    public function stop(Request $request): ?string
    {
        $admin = self::impersonator();
        $target = $request->user();
        $returnTo = $request->session()->get(self::RETURN_KEY);
        $request->session()->forget([self::SESSION_KEY, self::RETURN_KEY]);
        if ($admin === null || ! $admin->isAdmin() || $admin->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        AuditLog::record('user.impersonate_stop', $target, ['email' => $target?->email], $admin);

        return $returnTo;
    }
}
