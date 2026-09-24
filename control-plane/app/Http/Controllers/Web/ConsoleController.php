<?php

namespace App\Http\Controllers\Web;

use App\Domain\Console\ConsoleSessions;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Konsola maszyny w przeglądarce: noVNC dla KVM, xterm.js dla kontenerów.
 *
 * Adres hypervisora ani sekret węzła nigdy nie trafiają do przeglądarki —
 * dostaje ona tylko jednorazowy identyfikator sesji dla przekaźnika konsoli.
 */
class ConsoleController extends Controller
{
    public function __construct(private readonly ConsoleSessions $sessions) {}

    /** Przycisk „Konsola" w panelu — bilet i od razu przejście na stronę konsoli. */
    public function open(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('console', $server);

        if (! $server->isRunning()) {
            return back()->withErrors(['console' => 'Konsola jest dostępna tylko dla działającej maszyny.']);
        }

        return redirect()->route('console.show', [
            'token' => $this->sessions->issueTicket($server, $request->user()),
        ]);
    }

    public function show(Request $request, string $token): View
    {
        $ticket = $this->sessions->takeTicket($token);

        abort_if($ticket === null, 410, 'Bilet konsoli wygasł. Otwórz konsolę ponownie z panelu.');

        $server = Server::findOrFail($ticket['server_id']);

        abort_unless(
            $request->user()?->id === $ticket['user_id'] || $request->user()?->isStaff(),
            403,
        );

        $enabled = ConsoleSessions::enabled();

        return view('console', [
            'server' => $server,
            'enabled' => $enabled,
            'kind' => $server->isContainer() ? 'terminal' : 'vnc',
            'session' => $enabled ? $this->sessions->openSession($server) : null,
            // Hasło VNC obsługuje noVNC po stronie przeglądarki — agent jest
            // tylko rurą. Działa wyłącznie przez uwierzytelniony tunel.
            'vncPassword' => $server->isContainer() ? null : $server->vnc_password,
        ]);
    }
}
