<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Network\IpPoolManager;
use App\Domain\Provisioning\IpAllocator;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HypervisorGroup;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IpPoolController extends Controller
{
    public function index(): JsonResponse
    {
        $pools = IpPool::query()->with(['hypervisor', 'group'])->withCount([
            'addresses',
            'addresses as assigned_count' => fn ($q) => $q->whereNotNull('server_id'),
            'addresses as reserved_count' => fn ($q) => $q->where('is_reserved', true),
        ])->get();

        return response()->json([
            'data' => $pools->map(fn (IpPool $pool) => [
                'id' => $pool->id,
                'name' => $pool->name,
                'type' => $pool->type,
                'cidr' => $pool->cidr,
                'gateway' => $pool->gateway,
                'prefix' => $pool->prefix,
                'version' => $pool->version,
                'range_from' => $pool->range_from,
                'range_to' => $pool->range_to,
                'scope' => $pool->isGroupPool() ? 'group' : 'hypervisor',
                'hypervisor' => $pool->hypervisor?->name,
                'hypervisor_group' => $pool->group?->name,
                'nat' => $pool->isNat() ? [
                    'public_address' => $pool->nat_public_address,
                    'port_start' => $pool->nat_port_start,
                    'ports_per_server' => $pool->nat_ports_per_server,
                    'port_span' => $pool->natPortSpan(),
                ] : null,
                'total' => $pool->addresses_count,
                'assigned' => $pool->assigned_count,
                'reserved' => $pool->reserved_count,
                // Pula IPv6 nie jest rozwinięta — liczba wolnych nie ma sensu.
                'free' => $pool->version === 4
                    ? $pool->addresses_count - $pool->assigned_count - $pool->reserved_count
                    : null,
            ]),
        ]);
    }

    /**
     * Utworzenie puli. Pula IPv4 jest od razu rozwijana na pojedyncze adresy,
     * IPv6 — przydzielana leniwie przy zamówieniach.
     *
     * Zakres można zawęzić (range_from/range_to), bo w praktyce dostawca
     * przydziela podsieć, w której część adresów należy do infrastruktury hosta.
     */
    public function store(Request $request, IpPoolManager $pools): JsonResponse
    {
        ['pool' => $pool, 'imported' => $imported] = $pools->create(
            $request->validate(IpPoolManager::rules())
        );

        return response()->json([
            'message' => $pool->version === 4
                ? "Zaimportowano {$imported} adresów."
                : 'Dodano pulę IPv6 — adresy będą przydzielane przy zamówieniach.',
            'pool_id' => $pool->id,
            'imported' => $imported,
        ], 201);
    }

    public function destroy(IpPool $pool, IpPoolManager $pools): JsonResponse
    {
        $pools->delete($pool);

        return response()->json(['message' => 'Pula została usunięta.']);
    }

    public function addresses(Request $request, IpPool $pool): JsonResponse
    {
        $addresses = $pool->addresses()
            ->with('server:id,hostname,user_id')
            ->orderBy('id')
            ->paginate(100);

        return response()->json($addresses->through(fn (IpAddress $ip) => [
            'id' => $ip->id,
            'address' => $ip->address,
            'reserved' => $ip->is_reserved,
            'rdns' => $ip->rdns,
            'server' => $ip->server?->only(['id', 'hostname']),
        ]));
    }

    /**
     * Zwolnienie adresu przypisanego do maszyny wymaga świadomej decyzji —
     * maszyna straci łączność, dopóki nie dostanie innego adresu.
     */
    public function release(Request $request, IpAddress $address, IpAllocator $allocator): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']]);

        if ($address->is_primary && $address->server_id !== null) {
            return response()->json([
                'message' => 'To jest główny adres maszyny. Przypisz jej najpierw inny adres '
                    .'jako główny, zanim zwolnisz ten.',
            ], 409);
        }

        $serverId = $address->server_id;
        $allocator->release($address);

        AuditLog::record('ip.released', $address, ['server_id' => $serverId]);

        return response()->json(['message' => 'Adres wrócił do puli.']);
    }

    public function updateRdns(Request $request, IpAddress $address): JsonResponse
    {
        $validated = $request->validate([
            'rdns' => ['nullable', 'string', 'max:253'],
        ]);

        $address->forceFill(['rdns' => $validated['rdns'] ?? null])->save();

        return response()->json([
            'message' => 'Zapisano rekord PTR. Propagacja u dostawcy może potrwać do kilku godzin.',
        ]);
    }

    public function hypervisors(): JsonResponse
    {
        return response()->json([
            'data' => Hypervisor::query()->get(['id', 'name', 'hypervisor_group_id'])->all(),
            'groups' => HypervisorGroup::query()->get(['id', 'name'])->all(),
        ]);
    }
}
