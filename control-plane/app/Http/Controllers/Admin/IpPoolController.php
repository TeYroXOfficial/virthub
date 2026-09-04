<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Provisioning\IpAllocator;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IpPoolController extends Controller
{
    public function index(): JsonResponse
    {
        $pools = IpPool::query()->with('hypervisor')->withCount([
            'addresses',
            'addresses as assigned_count' => fn ($q) => $q->whereNotNull('server_id'),
            'addresses as reserved_count' => fn ($q) => $q->where('is_reserved', true),
        ])->get();

        return response()->json([
            'data' => $pools->map(fn (IpPool $pool) => [
                'id' => $pool->id,
                'name' => $pool->name,
                'cidr' => $pool->cidr,
                'gateway' => $pool->gateway,
                'prefix' => $pool->prefix,
                'version' => $pool->version,
                'hypervisor' => $pool->hypervisor->name,
                'total' => $pool->addresses_count,
                'assigned' => $pool->assigned_count,
                'reserved' => $pool->reserved_count,
                'free' => $pool->addresses_count - $pool->assigned_count - $pool->reserved_count,
            ]),
        ]);
    }

    /**
     * Utworzenie puli i rozwinięcie jej na pojedyncze adresy.
     *
     * Zakres można zawęzić (range_from/range_to), bo w praktyce dostawca
     * przydziela podsieć, w której część adresów należy do infrastruktury hosta.
     */
    public function store(Request $request, IpAllocator $allocator): JsonResponse
    {
        $validated = $request->validate([
            'hypervisor_id' => ['required', 'exists:hypervisors,id'],
            'name' => ['required', 'string', 'max:100'],
            'cidr' => ['required', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/'],
            'gateway' => ['required', 'ip'],
            'prefix' => ['required', 'integer', 'min:1', 'max:32'],
            'nameservers' => ['array', 'max:4'],
            'nameservers.*' => ['ip'],
            'range_from' => ['nullable', 'ip'],
            'range_to' => ['nullable', 'ip'],
        ]);

        $pool = IpPool::create([
            'hypervisor_id' => $validated['hypervisor_id'],
            'name' => $validated['name'],
            'cidr' => $validated['cidr'],
            'version' => 4,
            'gateway' => $validated['gateway'],
            'prefix' => $validated['prefix'],
            'nameservers' => $validated['nameservers'] ?? null,
        ]);

        $imported = $allocator->importPool(
            $pool,
            $validated['range_from'] ?? null,
            $validated['range_to'] ?? null,
        );

        AuditLog::record('ip_pool.imported', $pool, [
            'cidr' => $pool->cidr,
            'imported' => $imported,
        ]);

        return response()->json([
            'message' => "Zaimportowano {$imported} adresów.",
            'pool_id' => $pool->id,
            'imported' => $imported,
        ], 201);
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
            'data' => Hypervisor::query()->get(['id', 'name'])->all(),
        ]);
    }
}
