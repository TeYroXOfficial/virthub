<?php

namespace App\Http\Middleware;

use App\Domain\Security\AccountSecurity;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zasady bezpieczeństwa konta przy każdym żądaniu (sesja i token API):
 * dozwolone adresy IP konta, przypięcie sesji do adresu i przeglądarki,
 * limit zapytań tokenu API oraz obowiązkowa weryfikacja dwuetapowa dla
 * personelu i klientów, gdy administrator ją włączył.
 */
class EnforceAccountSecurity
{
    public function __construct(private readonly AccountSecurity $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        $viaToken = false;
        $user = Auth::guard('web')->user();
        if ($user === null && $request->bearerToken()) {
            $user = Auth::guard('sanctum')->user();
            $viaToken = true;
        }
        if ($user === null) {
            return $next($request);
        }

        if (! $this->security->ipAllowed($user, (string) $request->ip())) {
            if ($viaToken || $request->expectsJson()) {
                return response()->json(['message' => __('Dostęp do tego konta z adresu :ip jest zablokowany.', ['ip' => $request->ip()])], 403);
            }
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('Dostęp do tego konta z adresu :ip jest zablokowany.', ['ip' => $request->ip()]),
            ]);
        }

        // Sesja przypięta do adresu IP i przeglądarki: zmiana któregoś = wylogowanie.
        if (! $viaToken && $request->hasSession()) {
            $fingerprint = AccountSecurity::fingerprint($request);
            $stored = $request->session()->get('auth.fingerprint');
            if ($user->session_lock && $stored !== null && ! hash_equals($stored, $fingerprint)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                if ($request->expectsJson()) {
                    return response()->json(['message' => __('Sesja wygasła — zmienił się adres IP albo przeglądarka.')], 401);
                }

                return redirect()->route('login')->withErrors(['email' => __('Sesja wygasła — zmienił się adres IP albo przeglądarka. Zaloguj się ponownie.')]);
            }
            if ($stored === null) {
                $request->session()->put('auth.fingerprint', $fingerprint);
            }
        }

        if ($gate = $this->twoFactorGate($request, $user, $viaToken)) {
            return $gate;
        }

        // Limit zapytań tokenu API (na minutę): własny tokenu albo domyślny.
        $token = $viaToken ? $user->currentAccessToken() : null;
        if ($token instanceof PersonalAccessToken) {
            $limit = (int) ($token->rate_limit ?: config('virthub.api_token_rate_limit', 120));
            $key = 'api-token:'.$token->getKey();
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return response()->json(['message' => __('Przekroczono limit :limit zapytań na minutę dla tego tokenu.', ['limit' => $limit])], 429, [
                    'Retry-After' => RateLimiter::availableIn($key), 'X-RateLimit-Limit' => $limit, 'X-RateLimit-Remaining' => 0,
                ]);
            }
            RateLimiter::hit($key, 60);
            $response = $next($request);
            $response->headers->set('X-RateLimit-Limit', (string) $limit);
            $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($key, $limit)));

            return $response;
        }

        return $next($request);
    }

    /** Konto bez wymaganego drugiego składnika: personel nie wejdzie do administracji, klient do panelu. */
    private function twoFactorGate(Request $request, User $user, bool $viaToken): ?Response
    {
        if (! $this->security->mustEnableTwoFactor($user)) {
            return null;
        }
        $customer = $user->role === User::ROLE_CUSTOMER;
        $blocked = $customer
            ? ($request->routeIs('panel.*') || $request->is('api/*')) && ! $request->routeIs('panel.account', 'panel.account.*')
            : $request->routeIs('panel.admin.*') || $request->is('api/v1/admin/*');
        if (! $blocked) {
            return null;
        }
        $message = $customer
            ? __('Włącz weryfikację dwuetapową (kod z aplikacji albo klucz dostępu), aby korzystać z panelu.')
            : __('Włącz weryfikację dwuetapową na swoim koncie, aby korzystać z administracji.');
        if ($viaToken || $request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('panel.account.security')->withErrors(['two_factor' => $message]);
    }
}
