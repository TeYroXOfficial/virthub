<?php

namespace App\Http\Controllers\Web;

use App\Domain\Network\Firewall;
use App\Http\Controllers\Controller;
use App\Models\FirewallRule;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Zapora maszyny w panelu — klient i personel na tej samej stronie maszyny. */
class FirewallController extends Controller
{
    public function __construct(private readonly Firewall $firewall) {}

    public function policy(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('firewall', $server);

        $this->firewall->updatePolicy($server, $request->user(), [
            ...$request->validate([
                'inbound' => ['required', Rule::in(['accept', 'drop'])],
                'outbound' => ['required', Rule::in(['accept', 'drop'])],
            ]),
            'enabled' => $request->boolean('enabled'),
            'locked' => $request->boolean('locked'),
        ]);

        return $this->done($server, 'Zapisano ustawienia zapory. Zmiana trafi na węzeł w ciągu kilku sekund.');
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('firewall', $server);

        $this->firewall->addRule($server, $request->user(), $request->validate(Firewall::ruleRules()));

        return $this->done($server, 'Reguła została dodana.');
    }

    public function preset(Request $request, Server $server, string $preset): RedirectResponse
    {
        $this->authorize('firewall', $server);

        $this->firewall->addPreset($server, $request->user(), $preset);

        return $this->done($server, 'Dodano reguły: '.Firewall::PRESETS[$preset]['label'].'.');
    }

    public function destroy(Request $request, Server $server, FirewallRule $rule): RedirectResponse
    {
        $this->authorize('firewall', $server);

        $this->firewall->deleteRule($server, $request->user(), $rule);

        return $this->done($server, 'Reguła została usunięta.');
    }

    public function toggle(Request $request, Server $server, FirewallRule $rule): RedirectResponse
    {
        $this->authorize('firewall', $server);

        $this->firewall->toggleRule($server, $request->user(), $rule);

        return $this->done($server, $rule->fresh()->enabled ? 'Reguła włączona.' : 'Reguła wyłączona.');
    }

    public function move(Request $request, Server $server, FirewallRule $rule, string $direction): RedirectResponse
    {
        $this->authorize('firewall', $server);
        abort_unless(in_array($direction, ['up', 'down'], true), 404);

        $this->firewall->moveRule($server, $request->user(), $rule, $direction);

        return $this->done($server, 'Zmieniono kolejność reguł.');
    }

    private function done(Server $server, string $message): RedirectResponse
    {
        return redirect()->to(route('panel.servers.show', $server).'#firewall')->with('status', $message);
    }
}
