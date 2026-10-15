<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\ServerSharing;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerShare;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Udostępnianie maszyny (właściciel) i zmiana właściciela (administrator). */
class ServerShareController extends Controller
{
    public function store(Request $request, Server $server, ServerSharing $sharing): RedirectResponse
    {
        $this->authorize('share', $server);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(ServerShare::PERMISSIONS)],
        ]);

        return $this->guard($server, fn () => $sharing->share($server, $data['email'], $data['permissions'] ?? [], $request->user()),
            __('Udostępniono maszynę :email.', ['email' => $data['email']]));
    }

    public function destroy(Request $request, Server $server, ServerShare $share, ServerSharing $sharing): RedirectResponse
    {
        abort_unless($share->server_id === $server->id, 404);
        // Udostępniony sam może zrezygnować z dostępu.
        $request->user()->id === $share->user_id || $this->authorize('share', $server);
        $sharing->unshare($share, $request->user());
        if ($request->user()->id === $share->user_id) {
            return redirect()->route('panel.dashboard')->with('status', __('Zrezygnowano z dostępu do maszyny.'));
        }

        return redirect()->to(route('panel.servers.show', $server).'#sharing')->with('status', __('Cofnięto udostępnienie.'));
    }

    public function transfer(Request $request, Server $server, ServerSharing $sharing): RedirectResponse
    {
        $this->authorize('transfer', $server);
        $data = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'with_billing' => ['nullable', 'boolean'],
        ], ['email.exists' => __('Nie ma konta z tym adresem e-mail.')]);
        $owner = User::query()->where('email', $data['email'])->firstOrFail();

        return $this->guard($server, fn () => $sharing->transfer($server, $owner, $request->boolean('with_billing'), $request->user()),
            __('Właścicielem maszyny jest teraz :email.', ['email' => $owner->email]));
    }

    private function guard(Server $server, \Closure $action, string $message): RedirectResponse
    {
        $to = redirect()->to(route('panel.servers.show', $server).'#sharing');
        try {
            $action();
        } catch (\DomainException $e) {
            return $to->withErrors(['sharing' => $e->getMessage()]);
        }

        return $to->with('status', $message);
    }
}
