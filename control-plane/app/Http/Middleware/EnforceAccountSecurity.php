<?php

namespace App\Http\Middleware;

use App\Domain\Security\AccountSecurity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zasady bezpieczeństwa konta przy każdym żądaniu (sesja i token API):
 * dozwolone adresy IP konta oraz obowiązkowa weryfikacja dwuetapowa dla
 * personelu, gdy administrator ją włączył.
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

        $adminArea = $request->routeIs('panel.admin.*') || $request->is('api/v1/admin/*');
        if ($adminArea && $this->security->mustEnableTwoFactor($user)) {
            if ($viaToken || $request->expectsJson()) {
                return response()->json(['message' => __('Włącz weryfikację dwuetapową na swoim koncie, aby korzystać z administracji.')], 403);
            }

            return redirect()->route('panel.account')->withErrors([
                'two_factor' => __('Włącz weryfikację dwuetapową na swoim koncie, aby korzystać z administracji.'),
            ]);
        }

        return $next($request);
    }
}
