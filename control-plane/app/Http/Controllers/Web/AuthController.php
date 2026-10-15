<?php

namespace App\Http\Controllers\Web;

use App\Domain\Access\Impersonation;
use App\Domain\Security\AccountSecurity;
use App\Domain\Security\LoginFlow;
use App\Domain\Security\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly AccountSecurity $security,
    ) {}

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

        $user = $request->user();
        if (! $this->security->ipAllowed($user, (string) $request->ip())) {
            Auth::logout();
            RateLimiter::hit($key, 300);
            AuditLog::record('auth.ip_blocked', $user, ['ip' => $request->ip()], $user);

            throw ValidationException::withMessages([
                'email' => __('Dostęp do tego konta z adresu :ip jest zablokowany.', ['ip' => $request->ip()]),
            ]);
        }

        RateLimiter::clear($key);

        // Hasło się zgadza, ale konto ma 2FA — sesja powstanie dopiero po kodzie z aplikacji albo kluczu dostępu.
        if ($this->security->hasSecondFactor($user)) {
            Auth::logout();
            $request->session()->regenerate();
            $request->session()->put('login.2fa', [
                'id' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires' => now()->addMinutes(10)->getTimestamp(),
            ]);

            return redirect()->route('login.two-factor');
        }

        return $this->completeLogin($request, $user);
    }

    public function showTwoFactor(Request $request): View|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('login');
        }

        $user = $this->pendingUser($request);

        return view('auth.two-factor', ['totp' => $this->twoFactor->enabled($user), 'passkeys' => $user->passkeys()->exists()]);
    }

    public function twoFactor(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->route('login')->withErrors(['email' => __('Logowanie wygasło — wpisz hasło ponownie.')]);
        }
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:32']])['code'];

        $key = '2fa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => __('Za dużo prób logowania. Spróbuj ponownie za :seconds s.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }
        if (! $this->twoFactor->verify($user, $code)) {
            RateLimiter::hit($key, 300);
            AuditLog::record('auth.2fa_failed', $user, ['ip' => $request->ip()], $user);

            throw ValidationException::withMessages(['code' => __('Nieprawidłowy kod.')]);
        }
        RateLimiter::clear($key);

        $remember = (bool) $request->session()->pull('login.2fa')['remember'];
        Auth::login($user, $remember);

        return $this->completeLogin($request, $user);
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('login.2fa');
        if (! is_array($pending) || ($pending['expires'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget('login.2fa');

            return null;
        }
        $user = User::find($pending['id'] ?? 0);

        return $user && ! $user->isSuspended() && $this->security->hasSecondFactor($user) ? $user : null;
    }

    /** Użytkownik po haśle, czekający na drugi składnik (dla logowania kluczem dostępu). */
    public function pendingTwoFactorUser(Request $request): ?User
    {
        return $this->pendingUser($request);
    }

    private function completeLogin(Request $request, User $user): RedirectResponse
    {
        return redirect()->to(LoginFlow::complete($request, $user));
    }

    public function logout(Request $request): RedirectResponse
    {
        // Wylogowanie podczas „Zaloguj jako” wraca na konto administratora.
        if (Impersonation::active()) {
            $returnTo = app(Impersonation::class)->stop($request);

            return $returnTo ? redirect()->to($returnTo) : redirect()->route('login');
        }

        AuditLog::record('auth.logout', $request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
