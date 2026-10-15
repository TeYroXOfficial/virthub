<?php

namespace App\Domain\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/** Wspólne zakończenie logowania (hasło, hasło + 2FA, klucz dostępu). */
class LoginFlow
{
    public static function complete(Request $request, User $user, string $method = 'password'): string
    {
        $request->session()->regenerate();
        $request->session()->put('auth.fingerprint', AccountSecurity::fingerprint($request));
        StepUp::confirm($request);
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::record('auth.login', $user, $method === 'password' ? [] : ['method' => $method]);

        return redirect()->intended(route('panel.dashboard'))->getTargetUrl();
    }
}
