<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Wymiana biletu konsoli na sesję.
 *
 * Bilet jest jednorazowy i ważny minutę. Adres hypervisora ani port VNC nigdy
 * nie trafiają do przeglądarki — po wdrożeniu proxy (Faza 2) ta strona otworzy
 * WebSocket do proxy, które samo zestawi tunel do gniazda VNC na hoście.
 */
class ConsoleController extends Controller
{
    public function __invoke(Request $request, string $token): View
    {
        $payload = Cache::pull("console:{$token}");

        abort_if($payload === null, 410, 'Bilet konsoli wygasł. Otwórz konsolę ponownie z panelu.');

        $server = Server::findOrFail($payload['server_id']);

        abort_unless(
            $request->user()?->id === $payload['user_id'] || $request->user()?->isStaff(),
            403,
        );

        return view('console', [
            'server' => $server,
            // Proxy WebSocket jest osobnym komponentem z Fazy 2 — do czasu jego
            // wdrożenia strona mówi to wprost, zamiast wisieć na połączeniu.
            'proxyConfigured' => (bool) config('virthub.console_proxy_url'),
            'proxyUrl' => config('virthub.console_proxy_url'),
        ]);
    }
}
