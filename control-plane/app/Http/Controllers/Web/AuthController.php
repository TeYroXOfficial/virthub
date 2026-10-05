<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Ograniczenie prób per adres e-mail i IP — bez tego panel z publicznym
        // logowaniem jest wygodnym celem do zgadywania haseł.
        $key = 'login:'.mb_strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => __('Za dużo prób logowania. Spróbuj ponownie za :seconds s.', ['seconds' => $seconds]),
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'email' => __('Nieprawidłowy e-mail lub hasło.'),
            ]);
        }

        if ($request->user()->isSuspended()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => __('To konto jest zawieszone. Skontaktuj się z obsługą.'),
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();
        AuditLog::record('auth.login', $request->user());

        return redirect()->intended(route('panel.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        // Wylogowanie podczas „Zaloguj jako” wraca na konto administratora.
        if (\App\Domain\Access\Impersonation::active()) {
            $returnTo = app(\App\Domain\Access\Impersonation::class)->stop($request);

            return $returnTo ? redirect()->to($returnTo) : redirect()->route('login');
        }

        AuditLog::record('auth.logout', $request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
