<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NetworkFilterProfile;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/** Sieć → Profile filtrowania: anty-spoofing, RA, DHCP i reguły administratora. */
class NetworkFilterController extends Controller
{
    public function __construct(private readonly ServerProvisioner $provisioner) {}

    public function index(): View
    {
        return view('panel.admin.network.filters', [
            'profiles' => NetworkFilterProfile::query()->withCount('servers')->orderByDesc('is_default')->orderBy('name')->get(),
            'defaultServers' => Server::query()->whereNull('network_filter_profile_id')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $profile = NetworkFilterProfile::create($this->validated($request));
        AuditLog::record('network_filter.created', $profile, ['name' => $profile->name]);
        if ($profile->is_default) {
            $this->makeDefault($profile);
        }

        return back()->with('status', __('Zapisano profil :name.', ['name' => $profile->name]));
    }

    public function update(Request $request, NetworkFilterProfile $profile): RedirectResponse
    {
        $wasDefault = $profile->is_default;
        $profile->update($this->validated($request, $profile));
        AuditLog::record('network_filter.updated', $profile, ['name' => $profile->name]);
        if ($profile->is_default && ! $wasDefault) {
            $this->makeDefault($profile);
        }
        $count = $this->resync($profile);

        return back()->with('status', trans_choice('Zapisano profil — reguły trafią na :count maszynę.|Zapisano profil — reguły trafią na :count maszyny.|Zapisano profil — reguły trafią na :count maszyn.', $count));
    }

    public function destroy(NetworkFilterProfile $profile): RedirectResponse
    {
        $affected = Server::query()->where('network_filter_profile_id', $profile->id)->pluck('id')->all();
        AuditLog::record('network_filter.deleted', $profile, ['name' => $profile->name]);
        $wasDefault = $profile->is_default;
        $profile->delete();
        $this->dispatch(Server::query()->when(! $wasDefault, fn ($q) => $q->whereIn('id', $affected), fn ($q) => $q->where(fn ($w) => $w->whereNull('network_filter_profile_id')->orWhereIn('id', $affected))));

        return back()->with('status', __('Profil usunięty.'));
    }

    /** Profil per maszyna (strona maszyny → Ustawienia). */
    public function assign(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('manageResources', $server);
        $id = $request->validate(['network_filter_profile_id' => ['nullable', 'integer', 'exists:network_filter_profiles,id']])['network_filter_profile_id'] ?? null;
        $server->forceFill(['network_filter_profile_id' => $id])->save();
        AuditLog::record('server.network_filter', $server, ['profile_id' => $id]);
        if ($server->agent_uuid) {
            $this->provisioner->syncNetwork($server, $request->user());
        }

        return redirect()->to(route('panel.servers.show', $server).'#network-filter')->with('status', __('Profil filtrowania trafi na węzeł w ciągu kilku sekund.'));
    }

    private function makeDefault(NetworkFilterProfile $profile): void
    {
        NetworkFilterProfile::query()->whereKeyNot($profile->id)->update(['is_default' => false]);
        $this->dispatch(Server::query()->whereNull('network_filter_profile_id'));
    }

    private function resync(NetworkFilterProfile $profile): int
    {
        return $this->dispatch(Server::query()->where(function ($q) use ($profile) {
            $q->where('network_filter_profile_id', $profile->id);
            if ($profile->is_default) {
                $q->orWhereNull('network_filter_profile_id');
            }
        }));
    }

    /** Nowe reguły na węzły maszyn, które już istnieją u agenta. */
    private function dispatch(Builder $query): int
    {
        $count = 0;
        $query->whereNotNull('agent_uuid')->chunkById(200, function ($servers) use (&$count) {
            foreach ($servers as $server) {
                try {
                    $this->provisioner->syncNetwork($server);
                    $count++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        return $count;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?NetworkFilterProfile $profile = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'rules' => ['nullable', 'string', 'max:20000'],
        ]);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'anti_spoofing' => $request->boolean('anti_spoofing'),
            'block_router_advertisements' => $request->boolean('block_router_advertisements'),
            'block_dhcp_server' => $request->boolean('block_dhcp_server'),
            'rules' => $this->parseRules((string) ($data['rules'] ?? '')),
            'is_default' => $request->boolean('is_default') || ($profile?->is_default ?? false),
        ];
    }

    /**
     * Reguły jako JSON: [{"action":"drop","direction":"out","protocol":"tcp","port_from":25}]
     *
     * @return list<array<string, mixed>>
     */
    private function parseRules(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $rules = json_decode($json, true);
        $fail = fn (string $message) => throw ValidationException::withMessages(['rules' => $message]);
        if (! is_array($rules) || ! array_is_list($rules)) {
            $fail(__('Reguły muszą być listą JSON, np. [{"action": "drop", "direction": "out", "protocol": "tcp", "port_from": 25}].'));
        }
        if (count($rules) > 100) {
            $fail(__('Najwyżej 100 reguł w profilu.'));
        }
        $out = [];
        foreach ($rules as $i => $rule) {
            $n = $i + 1;
            if (! is_array($rule)) {
                $fail(__('Reguła :n nie jest obiektem.', ['n' => $n]));
            }
            $action = $rule['action'] ?? 'drop';
            $direction = $rule['direction'] ?? 'in';
            $protocol = $rule['protocol'] ?? 'any';
            $from = $rule['port_from'] ?? null;
            $to = $rule['port_to'] ?? null;
            $source = $rule['source'] ?? null;
            if (! in_array($action, ['accept', 'drop'], true) || ! in_array($direction, ['in', 'out'], true) || ! in_array($protocol, ['tcp', 'udp', 'icmp', 'any'], true)) {
                $fail(__('Reguła :n: action = accept|drop, direction = in|out, protocol = tcp|udp|icmp|any.', ['n' => $n]));
            }
            foreach ([$from, $to] as $port) {
                if ($port !== null && (! is_int($port) || $port < 1 || $port > 65535)) {
                    $fail(__('Reguła :n: port musi być liczbą 1–65535.', ['n' => $n]));
                }
            }
            if (($from !== null || $to !== null) && ! in_array($protocol, ['tcp', 'udp'], true)) {
                $fail(__('Reguła :n: porty tylko dla tcp i udp.', ['n' => $n]));
            }
            if ($to !== null && ($from === null || $to < $from)) {
                $fail(__('Reguła :n: port_to wymaga port_from i nie może być mniejszy.', ['n' => $n]));
            }
            if ($source !== null && ! $this->validCidr((string) $source)) {
                $fail(__('Reguła :n: source musi być adresem IP albo siecią CIDR.', ['n' => $n]));
            }
            $out[] = ['action' => $action, 'direction' => $direction, 'protocol' => $protocol, 'port_from' => $from, 'port_to' => $to, 'source' => $source];
        }

        return $out;
    }

    private function validCidr(string $value): bool
    {
        [$ip, $mask] = array_pad(explode('/', $value, 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return $mask === null || (ctype_digit($mask) && (int) $mask <= (str_contains($ip, ':') ? 128 : 32));
    }
}
