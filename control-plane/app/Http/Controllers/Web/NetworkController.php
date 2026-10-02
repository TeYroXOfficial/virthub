<?php

namespace App\Http\Controllers\Web;

use App\Domain\Network\IpPoolManager;
use App\Domain\Network\MacAddress;
use App\Domain\Network\ReverseDns;
use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use App\Models\IpAddress;
use App\Models\IpPool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sieć: bloki IP (pule adresów) i przegląd adresów IPv4, IPv4 NAT i IPv6 —
 * które są przypisane do jakiej usługi, a które wolne.
 */
class NetworkController extends Controller
{
    // --- bloki IP ------------------------------------------------------------

    public function blocks(Request $request): View
    {
        $version = in_array($request->integer('version'), [4, 6], true) ? $request->integer('version') : null;

        return view('panel.admin.network.blocks', [
            'version' => $version,
            'pools' => IpPool::query()->with(['hypervisor', 'group'])
                ->when($version, fn ($q) => $q->where('version', $version))
                ->withCount($this->counts())
                ->orderBy('version')->orderBy('type')->orderBy('name')->get(),
            'hypervisors' => Hypervisor::query()->with('group')->orderBy('name')->get(),
            'groups' => HypervisorGroup::query()->with('hypervisors')->ordered()->get(),
            'totals' => [4 => IpPool::query()->where('version', 4)->count(), 6 => IpPool::query()->where('version', 6)->count()],
        ]);
    }

    public function store(Request $request, IpPoolManager $pools): RedirectResponse
    {
        ['pool' => $pool, 'imported' => $imported] = $pools->create($request->validate(IpPoolManager::rules()));

        return redirect()->route('panel.admin.ip-pools.show', $pool)->with('status', match (true) {
            $pool->version === 6 => __('Dodano blok IPv6 :name. Adresy będą przydzielane kolejno przy zamówieniach.', ['name' => $pool->name]),
            $imported === 0 => __('Utworzono pusty blok :name — dodaj do niego adresy poniżej.', ['name' => $pool->name]),
            default => __('Zaimportowano :imported adresów do bloku :name.', ['imported' => $imported, 'name' => $pool->name]),
        });
    }

    public function show(Request $request, IpPool $pool): View
    {
        $pool->load(['hypervisor', 'group'])->loadCount($this->counts());
        $status = $this->status($request);

        $addresses = $pool->addresses()->with(['server.user:id,email', 'hypervisor:id,name'])
            ->tap(fn (Builder $q) => $this->filterStatus($q, $status))
            ->when($request->filled('q'), fn ($q) => $q->where('address', 'like', trim((string) $request->query('q')).'%'))
            ->orderByRaw('length(address)')->orderBy('address')
            ->paginate(100)->withQueryString();

        return view('panel.admin.network.block', [
            'pool' => $pool,
            'addresses' => $addresses,
            'status' => $status,
            'hypervisors' => Hypervisor::query()->with('group')->orderBy('name')->get(),
            'groups' => HypervisorGroup::query()->ordered()->get(),
            'preset' => IpPoolManager::presetFor($pool->nameservers),
        ]);
    }

    public function update(Request $request, IpPool $pool, IpPoolManager $pools): RedirectResponse
    {
        $pools->update($pool, $request->validate(IpPoolManager::updateRules()));

        return back()->with('status', __('Zapisano blok :name.', ['name' => $pool->name]));
    }

    public function destroy(IpPool $pool, IpPoolManager $pools): RedirectResponse
    {
        $pools->delete($pool);

        return redirect()->route('panel.admin.ip-pools')->with('status', __('Blok :name został usunięty.', ['name' => $pool->name]));
    }

    public function addAddresses(Request $request, IpPool $pool, IpPoolManager $pools): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['single', 'range', 'subnet'])],
            'addresses' => ['nullable', 'string', 'max:40000'],
            'from' => ['nullable', 'string', 'max:64'],
            'to' => ['nullable', 'string', 'max:64'],
            'cidr' => ['nullable', 'string', 'max:64'],
        ]);
        ['added' => $added, 'skipped' => $skipped] = $pools->addAddresses($pool, $data);

        return back()->with('status', $skipped
            ? __('Dodano :added adresów (:skipped już było w panelu).', ['added' => $added, 'skipped' => $skipped])
            : __('Dodano :added adresów.', ['added' => $added]));
    }

    /**
     * Akcje na adresie: rDNS i MAC (edit), rezerwacja albo usunięcie wolnego adresu.
     * Nowy MAC adresu przydzielonego maszynie trafia od razu na węzeł.
     */
    public function address(Request $request, IpAddress $address, ReverseDns $rdns, ServerProvisioner $provisioner): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['edit', 'reserve', 'unreserve', 'delete'])]])['action'];

        if ($action === 'edit') {
            $data = $request->validate([
                'rdns' => ['nullable', 'string', 'max:253'],
                'mac_address' => ['nullable', 'string', 'max:17'],
            ]);
            $mac = MacAddress::normalize($data['mac_address'] ?? null);
            if ($mac !== null && IpAddress::query()->where('mac_address', $mac)->whereKeyNot($address->id)
                ->whereNotNull('server_id')->when($address->server_id, fn ($q, $id) => $q->where('server_id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['mac_address' => __('Ten MAC ma już adres innej maszyny — dwie karty z tym samym MAC zablokują sieć.')]);
            }
            if (($data['rdns'] ?? null) !== $address->rdns) {
                $rdns->set($address, $data['rdns'] ?? null, $request->user(), asStaff: true);
            }
            $note = '';
            if ($mac !== $address->mac_address) {
                AuditLog::record('ip.mac', $address->pool, ['address' => $address->address, 'from' => $address->mac_address, 'to' => $mac], $request->user());
                $address->forceFill(['mac_address' => $mac])->save();
                if ($address->server && $provisioner->syncMac($address->server, $request->user())) {
                    $note = ' '.__('MAC trafi na węzeł; maszyna dostanie go po wyłączeniu i włączeniu.');
                }
            }

            return back()->with('status', __('Zapisano adres :address.', ['address' => $address->address]).$note);
        }

        if ($address->server_id !== null) {
            throw ValidationException::withMessages(['address' => __('Adres :address jest przypisany do maszyny — najpierw go zwolnij.', ['address' => $address->address])]);
        }

        if ($action === 'delete') {
            $address->delete();
        } else {
            $address->forceFill(['is_reserved' => $action === 'reserve'])->save();
        }
        AuditLog::record('ip.'.$action, $address->pool, ['address' => $address->address]);

        return back()->with('status', match ($action) {
            'reserve' => __('Adres :address zarezerwowany — nie trafi do żadnej usługi.', ['address' => $address->address]),
            'unreserve' => __('Adres :address znowu może być przydzielony.', ['address' => $address->address]),
            default => __('Adres :address usunięty z bloku.', ['address' => $address->address]),
        });
    }

    // --- adresy ------------------------------------------------------------------

    public function ipv4(Request $request): View
    {
        return $this->addresses($request, 'ipv4', fn (Builder $q) => $q->where('version', 4)
            ->whereHas('pool', fn ($p) => $p->where('type', IpPool::TYPE_PUBLIC)));
    }

    public function nat(Request $request): View
    {
        return $this->addresses($request, 'nat', fn (Builder $q) => $q->where('version', 4)
            ->whereHas('pool', fn ($p) => $p->where('type', IpPool::TYPE_NAT)));
    }

    public function ipv6(Request $request): View
    {
        return $this->addresses($request, 'ipv6', fn (Builder $q) => $q->where('version', 6));
    }

    private function addresses(Request $request, string $kind, \Closure $scope): View
    {
        $status = $this->status($request);
        $term = trim((string) $request->query('q', ''));
        $base = IpAddress::query()->tap($scope);

        $addresses = (clone $base)
            ->with(['pool:id,name,type,version,cidr,nat_port_start,nat_ports_per_server,nat_public_address,hypervisor_id,hypervisor_group_id', 'server.user:id,email', 'hypervisor:id,name'])
            ->tap(fn (Builder $q) => $this->filterStatus($q, $status))
            ->when($request->integer('pool'), fn ($q, $id) => $q->where('ip_pool_id', $id))
            ->when($request->integer('node'), fn ($q, $id) => $q->where('hypervisor_id', $id))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('address', 'like', "{$term}%")
                ->orWhere('rdns', 'like', "%{$term}%")
                ->orWhereHas('server', fn ($s) => $s->where('hostname', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$term}%")))))
            ->orderBy('ip_pool_id')->orderByRaw('length(address)')->orderBy('address')
            ->paginate(100)->withQueryString();

        $counts = [
            'all' => (clone $base)->count(),
            'assigned' => (clone $base)->whereNotNull('server_id')->count(),
            'free' => (clone $base)->whereNull('server_id')->where('is_reserved', false)->count(),
            'reserved' => (clone $base)->whereNull('server_id')->where('is_reserved', true)->count(),
        ];
        $poolType = $kind === 'nat' ? IpPool::TYPE_NAT : ($kind === 'ipv4' ? IpPool::TYPE_PUBLIC : null);

        return view('panel.admin.network.addresses', [
            'kind' => $kind,
            'addresses' => $addresses,
            'status' => $status,
            'counts' => $counts,
            'term' => $term,
            'pools' => IpPool::query()->where('version', $kind === 'ipv6' ? 6 : 4)
                ->when($poolType, fn ($q) => $q->where('type', $poolType))->orderBy('name')->get(['id', 'name', 'cidr']),
            'nodes' => Hypervisor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // --- pomocnicze --------------------------------------------------------------

    /** @return array<int|string, mixed> */
    private function counts(): array
    {
        return [
            'addresses',
            'addresses as assigned_count' => fn ($q) => $q->whereNotNull('server_id'),
            'addresses as reserved_count' => fn ($q) => $q->whereNull('server_id')->where('is_reserved', true),
        ];
    }

    private function status(Request $request): string
    {
        return in_array($request->query('status'), ['assigned', 'free', 'reserved'], true) ? $request->query('status') : 'all';
    }

    private function filterStatus(Builder $query, string $status): void
    {
        match ($status) {
            'assigned' => $query->whereNotNull('server_id'),
            'free' => $query->whereNull('server_id')->where('is_reserved', false),
            'reserved' => $query->whereNull('server_id')->where('is_reserved', true),
            default => null,
        };
    }
}
