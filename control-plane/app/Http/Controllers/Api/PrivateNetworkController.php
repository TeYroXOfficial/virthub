<?php

namespace App\Http\Controllers\Api;

use App\Domain\Network\PrivateNetworks;
use App\Http\Controllers\Controller;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\ServerNic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sieci prywatne maszyny przez API: karty dodatkowe i sieci właściciela na węźle maszyny. */
class PrivateNetworkController extends Controller
{
    public function __construct(private readonly PrivateNetworks $networks) {}

    public function index(Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        return response()->json([
            'data' => $server->nics()->with('network')->get()->map(fn (ServerNic $nic) => $this->nic($nic))->values(),
            'networks' => PrivateNetwork::query()->where('user_id', $server->user_id)->where('hypervisor_id', $server->hypervisor_id)
                ->withCount('nics')->orderBy('name')->get()
                ->map(fn (PrivateNetwork $n) => ['id' => $n->id, 'name' => $n->name, 'cidr' => $n->cidr, 'servers' => $n->nics_count])->values(),
            'limit' => (int) config('virthub.private_networks.nics_per_server', 4),
        ]);
    }

    public function attach(Request $request, Server $server): JsonResponse
    {
        $this->authorize('firewall', $server);
        $data = $request->validate([
            'network_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'required_without:network_id', 'string', 'max:64'],
            'cidr' => ['nullable', 'string', 'max:18'],
            'address' => ['nullable', 'ip'],
        ]);
        $network = ! empty($data['network_id'])
            ? PrivateNetwork::query()->where('user_id', $server->user_id)->findOrFail($data['network_id'])
            : null;
        if ($network === null) {
            $this->networks->assertAttachableToNew($server);
            $network = $this->networks->create($server->user, $server->hypervisor, $data['name'], $data['cidr'] ?? null, $request->user());
        }
        $job = $this->networks->attach($server, $network, $data['address'] ?? null, $request->user());
        $nic = $server->nics()->with('network')->where('private_network_id', $network->id)->firstOrFail();

        return response()->json(['nic' => $this->nic($nic), 'job_id' => $job->id, 'status' => $job->status], 202);
    }

    public function detach(Request $request, Server $server, ServerNic $nic): JsonResponse
    {
        $this->authorize('firewall', $server);
        abort_unless($nic->server_id === $server->id, 404);
        $job = $this->networks->detach($nic, $request->user());

        return response()->json(['job_id' => $job->id, 'status' => $job->status], 202);
    }

    /** @return array<string, mixed> */
    private function nic(ServerNic $nic): array
    {
        return [
            'id' => $nic->id, 'slot' => $nic->slot, 'mac' => $nic->effectiveMac(),
            'address' => $nic->address, 'prefix' => $nic->network->prefix(),
            'network' => ['id' => $nic->network->id, 'name' => $nic->network->name, 'cidr' => $nic->network->cidr],
        ];
    }
}
