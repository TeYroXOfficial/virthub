<?php

namespace App\Http\Controllers\Web;

use App\Domain\Apps\Pterodactyl;
use App\Domain\Apps\PterodactylMigrator;
use App\Http\Controllers\Controller;
use App\Models\AppServer;
use App\Models\Hypervisor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

/**
 * Migracja serwerów z Pterodactyla w trzech krokach: połącz (adres i klucze
 * API), zaznacz serwery, przenieś. Klucze leżą zaszyfrowane w sesji.
 */
class PterodactylMigrationController extends Controller
{
    private const SESSION = 'pterodactyl_migration';

    public function index(Request $request): View
    {
        $credentials = $this->credentials($request);
        $servers = [];
        $error = null;
        if ($credentials) {
            try {
                $servers = $this->api($credentials)->servers();
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        // Serwery już przeniesione z tego panelu — żeby nie przenieść drugi raz.
        $migrated = \App\Models\AuditLog::query()->where('action', 'app.migrated')->get()
            ->filter(fn ($log) => ($log->meta['from'] ?? null) === ($credentials['url'] ?? null))
            ->map(fn ($log) => $log->meta['identifier'] ?? null)->filter()->flip();

        return view('panel.admin.apps.pterodactyl', [
            'credentials' => $credentials,
            'servers' => $servers,
            'error' => $error,
            'migrated' => $migrated,
            'nodes' => Hypervisor::query()->where('apps_enabled', true)->orderBy('name')->get(),
            'created' => session('migration_created', []),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:http,https', 'max:255'],
            'application_key' => ['required', 'string', 'max:200', 'starts_with:ptla_'],
            'client_key' => ['required', 'string', 'max:200', 'starts_with:ptlc_'],
        ], [
            'application_key.starts_with' => __('Klucz aplikacji zaczyna się od ptla_ (Admin → Application API).'),
            'client_key.starts_with' => __('Klucz klienta zaczyna się od ptlc_ (Konto → API Credentials konta administratora).'),
        ]);
        $credentials = ['url' => Pterodactyl::normalizeUrl($data['url'])] + $data;

        try {
            $this->api($credentials)->servers();
        } catch (\RuntimeException $e) {
            return back()->withInput($request->except(['application_key', 'client_key']))->withErrors(['url' => $e->getMessage()]);
        }

        $request->session()->put(self::SESSION, Crypt::encryptString(json_encode($credentials)));

        return redirect()->route('panel.admin.apps.pterodactyl');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION);

        return redirect()->route('panel.admin.apps.pterodactyl');
    }

    public function migrate(Request $request, PterodactylMigrator $migrator): RedirectResponse
    {
        $credentials = $this->credentials($request);
        abort_if($credentials === null, 409, __('Najpierw połącz się z panelem Pterodactyl.'));
        $data = $request->validate([
            'servers' => ['required', 'array', 'min:1', 'max:100'],
            'servers.*' => ['string', 'max:40'],
            'node' => ['nullable', 'integer', 'exists:hypervisors,id'],
            'stop' => ['nullable', 'boolean'],
        ], ['servers.required' => __('Zaznacz co najmniej jeden serwer.')]);

        $api = $this->api($credentials);
        $selected = collect($api->servers())->whereIn('identifier', $data['servers']);
        $node = isset($data['node']) ? Hypervisor::query()->find($data['node']) : null;

        $done = 0;
        $errors = [];
        $created = [];
        foreach ($selected as $server) {
            try {
                $result = $migrator->migrate($api, $credentials, $server, $node, (bool) ($data['stop'] ?? false), $request->user());
                $done++;
                if ($result['password']) {
                    $created[] = ['email' => $result['app']->user->email, 'password' => $result['password']];
                }
            } catch (\DomainException|\RuntimeException|\Illuminate\Validation\ValidationException $e) {
                $errors[] = ($server['name'] ?? $server['identifier']).': '.$e->getMessage();
            }
        }

        $redirect = redirect()->route('panel.admin.apps.pterodactyl')
            ->with('migration_created', $created)
            ->with('status', trans_choice('Rozpoczęto przenoszenie :count serwera — postęp widać na liście aplikacji.|Rozpoczęto przenoszenie :count serwerów — postęp widać na liście aplikacji.|Rozpoczęto przenoszenie :count serwerów — postęp widać na liście aplikacji.', $done));

        return $errors ? $redirect->withErrors(['servers' => implode("\n", $errors)]) : $redirect;
    }

    private function credentials(Request $request): ?array
    {
        $sealed = $request->session()->get(self::SESSION);
        if (! is_string($sealed)) {
            return null;
        }
        try {
            return json_decode(Crypt::decryptString($sealed), true) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function api(array $credentials): Pterodactyl
    {
        return new Pterodactyl($credentials['url'], $credentials['application_key'], $credentials['client_key']);
    }
}
