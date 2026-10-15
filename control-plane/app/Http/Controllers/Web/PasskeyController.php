<?php

namespace App\Http\Controllers\Web;

use App\Domain\Security\AccountSecurity;
use App\Domain\Security\LoginFlow;
use App\Domain\Security\StepUp;
use App\Domain\Security\TwoFactor;
use App\Domain\Security\WebAuthn;
use App\Domain\Security\WebAuthnException;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Klucze dostępu (passkeys, FIDO2): rejestracja na koncie, logowanie bez
 * hasła, drugi składnik po haśle i ponowne potwierdzenie tożsamości (step-up).
 */
class PasskeyController extends Controller
{
    public function __construct(
        private readonly WebAuthn $webauthn,
        private readonly AccountSecurity $security,
        private readonly TwoFactor $twoFactor,
    ) {}

    // --- konto -----------------------------------------------------------------

    public function registerOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->passkeys()->count() >= WebauthnCredential::MAX_PER_USER) {
            return response()->json(['message' => __('Możesz mieć najwyżej :count kluczy dostępu.', ['count' => WebauthnCredential::MAX_PER_USER])], 422);
        }

        return response()->json($this->webauthn->creationOptions($request, $user));
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'id' => ['required', 'string', 'max:1400'],
            'clientDataJSON' => ['required', 'string', 'max:8192'],
            'attestationObject' => ['required', 'string', 'max:32768'],
            'transports' => ['nullable', 'array', 'max:6'],
            'transports.*' => ['string', 'max:20'],
        ]);
        $user = $request->user();
        if ($user->passkeys()->count() >= WebauthnCredential::MAX_PER_USER) {
            return response()->json(['message' => __('Możesz mieć najwyżej :count kluczy dostępu.', ['count' => WebauthnCredential::MAX_PER_USER])], 422);
        }
        try {
            $credential = $this->webauthn->register($request, $user, $data, (string) ($data['name'] ?? '') ?: WebAuthn::randomName());
        } catch (WebAuthnException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        AuditLog::record('account.passkey_added', $user, ['name' => $credential->name]);
        session()->flash('status', __('Dodano klucz dostępu :name.', ['name' => $credential->name]));

        return response()->json(['redirect' => route('panel.account.security')]);
    }

    public function destroy(Request $request, WebauthnCredential $passkey): RedirectResponse
    {
        $user = $request->user();
        abort_unless($passkey->user_id === $user->id, 404);
        // Ostatni drugi składnik przy wymaganym 2FA — usunięcie zablokowałoby konto.
        $required = $user->role === User::ROLE_CUSTOMER ? AccountSecurity::customersMustUseTwoFactor() : AccountSecurity::staffMustUseTwoFactor();
        if ($required && ! $this->twoFactor->enabled($user) && $user->passkeys()->count() === 1) {
            return back()->withErrors(['passkeys' => __('To jedyny drugi składnik, a administrator go wymaga — najpierw dodaj inny klucz albo kod z aplikacji.')]);
        }
        $passkey->delete();
        AuditLog::record('account.passkey_removed', $user, ['name' => $passkey->name]);

        return back()->with('status', __('Usunięto klucz dostępu :name.', ['name' => $passkey->name]));
    }

    /** Przypięcie sesji do adresu IP i przeglądarki. */
    public function sessionLock(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['session_lock' => $request->boolean('session_lock')])->save();
        $request->session()->put('auth.fingerprint', AccountSecurity::fingerprint($request));
        AuditLog::record('account.session_lock', $user, ['enabled' => $user->session_lock]);

        return back()->with('status', $user->session_lock
            ? __('Sesje są przypięte do adresu IP i przeglądarki — zmiana któregoś wymaga ponownego logowania.')
            : __('Sesje nie są już przypięte do adresu IP.'));
    }

    // --- logowanie bez hasła ------------------------------------------------------

    public function loginOptions(Request $request): JsonResponse
    {
        return response()->json($this->webauthn->requestOptions($request));
    }

    public function login(Request $request): JsonResponse
    {
        $key = 'passkey-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['message' => __('Za dużo prób logowania. Spróbuj ponownie za :seconds s.', ['seconds' => RateLimiter::availableIn($key)])], 429);
        }
        try {
            $credential = $this->webauthn->verify($request, $this->assertion($request), requireUv: true);
        } catch (WebAuthnException $e) {
            RateLimiter::hit($key, 300);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        $user = $credential->user;
        if ($error = $this->loginBlocked($request, $user)) {
            return response()->json(['message' => $error], 403);
        }
        RateLimiter::clear($key);
        Auth::login($user);

        return response()->json(['redirect' => LoginFlow::complete($request, $user, 'passkey')]);
    }

    // --- drugi składnik po haśle ----------------------------------------------------

    public function twoFactorOptions(Request $request, AuthController $auth): JsonResponse
    {
        $user = $auth->pendingTwoFactorUser($request);
        abort_if($user === null, 419);

        return response()->json($this->webauthn->requestOptions($request, $user));
    }

    public function twoFactor(Request $request, AuthController $auth): JsonResponse
    {
        $user = $auth->pendingTwoFactorUser($request);
        if ($user === null) {
            return response()->json(['message' => __('Logowanie wygasło — wpisz hasło ponownie.'), 'redirect' => route('login')], 419);
        }
        try {
            $this->webauthn->verify($request, $this->assertion($request), $user);
        } catch (WebAuthnException $e) {
            AuditLog::record('auth.2fa_failed', $user, ['ip' => $request->ip(), 'method' => 'passkey'], $user);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        $remember = (bool) ($request->session()->pull('login.2fa')['remember'] ?? false);
        Auth::login($user, $remember);

        return response()->json(['redirect' => LoginFlow::complete($request, $user, 'password+passkey')]);
    }

    // --- ponowne potwierdzenie (step-up) -------------------------------------------

    public function showConfirm(Request $request): View
    {
        $user = $request->user();

        return view('auth.confirm', [
            'totp' => $this->twoFactor->enabled($user),
            'passkeys' => $user->passkeys()->exists(),
            'minutes' => StepUp::minutes(),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['password' => ['nullable', 'string'], 'code' => ['nullable', 'string', 'max:32']]);
        $key = 'step-up:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => __('Za dużo prób. Spróbuj ponownie za :seconds s.', ['seconds' => RateLimiter::availableIn($key)])]);
        }
        $ok = ! empty($data['code'])
            ? $this->twoFactor->verify($user, (string) $data['code'])
            : ! empty($data['password']) && Auth::guard('web')->validate(['email' => $user->email, 'password' => $data['password']]);
        if (! $ok) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages([empty($data['code']) ? 'password' : 'code' => __('Nieprawidłowe dane — spróbuj ponownie.')]);
        }
        RateLimiter::clear($key);

        return $this->confirmed($request);
    }

    public function confirmOptions(Request $request): JsonResponse
    {
        return response()->json($this->webauthn->requestOptions($request, $request->user()));
    }

    public function confirmPasskey(Request $request): JsonResponse
    {
        try {
            $this->webauthn->verify($request, $this->assertion($request), $request->user());
        } catch (WebAuthnException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['redirect' => $this->confirmed($request)->getTargetUrl()]);
    }

    private function confirmed(Request $request): RedirectResponse
    {
        StepUp::confirm($request);
        AuditLog::record('auth.step_up', $request->user());
        $return = (string) $request->session()->pull('confirm.return', route('panel.dashboard'));
        // Tylko adresy panelu — parametr pochodzi z sesji, ale nie ufamy mu ślepo.
        if (! str_starts_with($return, url('/'))) {
            $return = route('panel.dashboard');
        }

        return redirect()->to($return)->with('status', __('Tożsamość potwierdzona na :minutes min — powtórz operację.', ['minutes' => StepUp::minutes()]));
    }

    /** @return array<string, mixed> */
    private function assertion(Request $request): array
    {
        return $request->validate([
            'id' => ['required', 'string', 'max:1400'],
            'clientDataJSON' => ['required', 'string', 'max:8192'],
            'authenticatorData' => ['required', 'string', 'max:8192'],
            'signature' => ['required', 'string', 'max:2048'],
            'userHandle' => ['nullable', 'string', 'max:512'],
        ]);
    }

    private function loginBlocked(Request $request, User $user): ?string
    {
        if ($user->isSuspended()) {
            return __('To konto jest zawieszone. Skontaktuj się z obsługą.');
        }
        if (! $this->security->ipAllowed($user, (string) $request->ip())) {
            AuditLog::record('auth.ip_blocked', $user, ['ip' => $request->ip()], $user);

            return __('Dostęp do tego konta z adresu :ip jest zablokowany.', ['ip' => $request->ip()]);
        }

        return null;
    }
}
