<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Logowanie jednorazowym biletem z systemu rozliczeniowego.
 *
 * Bilet jest kasowany przy pierwszym użyciu (Cache::pull), więc adres z
 * historii przeglądarki albo z logów proxy nie zaloguje nikogo drugi raz.
 */
class SsoController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $payload = Cache::pull("sso:{$token}");

        if ($payload === null) {
            return redirect()->route('login')->withErrors([
                'email' => 'Link logowania wygasł lub został już użyty. '
                    .'Wróć do panelu rozliczeniowego i kliknij ponownie.',
            ]);
        }

        $user = User::find($payload['user_id']);

        if ($user === null || $user->isSuspended()) {
            return redirect()->route('login')->withErrors([
                'email' => 'To konto jest niedostępne. Skontaktuj się z obsługą.',
            ]);
        }

        // Jawnie guard sesyjny: bilet SSO zawsze zakłada sesję w przeglądarce,
        // niezależnie od tego, jaki guard obsługiwał żądanie, które go wystawiło.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        AuditLog::record('auth.sso_login', $user, [], $user);

        return $payload['server_id'] !== null
            ? redirect()->route('panel.servers.show', $payload['server_id'])
            : redirect()->route('panel.dashboard');
    }
}
