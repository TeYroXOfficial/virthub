<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Provisioning\Migrations;
use App\Domain\Provisioning\ServerDisks;
use App\Domain\Provisioning\ServerHardware;
use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServerResource;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerDisk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ServerAdminController extends Controller
{
    public function __construct(private readonly ServerProvisioner $provisioner) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $servers = Server::query()
            ->with(['user:id,name,email', 'package', 'template', 'hypervisor', 'ipAddresses.pool'])
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->string('state')))
            ->when($request->filled('hypervisor_id'), fn ($q) => $q->where('hypervisor_id', $request->integer('hypervisor_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($sub) => $sub
                    ->where('hostname', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $term)));
            })
            ->latest()
            ->paginate(50);

        return ServerResource::collection($servers);
    }

    public function suspend(Request $request, Server $server): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $job = $this->provisioner->suspend($server, $validated['reason'], $request->user());

        return response()->json([
            'message' => __('Maszyna została zawieszona i jest zatrzymywana.'),
            'job_id' => $job->id,
        ], 202);
    }

    /** Migracja na inny hypervisor: mode = cold (zatrzymanie, kopia dysku) albo live. */
    public function migrate(Request $request, Server $server, Migrations $migrations): JsonResponse
    {
        $validated = $request->validate([
            'hypervisor_id' => ['required', 'integer', 'exists:hypervisors,id'],
            'mode' => ['nullable', Rule::in(Migrations::MODES)],
        ]);
        $job = $migrations->start($server, Hypervisor::findOrFail($validated['hypervisor_id']), $validated['mode'] ?? 'cold', $request->user());

        return response()->json(['message' => __('Migracja rozpoczęta.'), 'job_id' => $job->id], 202);
    }

    /** Dyski maszyny: główny i dodatkowe (vdb–vdh). */
    public function disks(Server $server): JsonResponse
    {
        return response()->json(['data' => $this->diskList($server)]);
    }

    public function addDisk(Request $request, Server $server, ServerDisks $disks): JsonResponse
    {
        $data = $request->validate(['size_gb' => ['required', 'integer', 'min:1', 'max:65536']]);
        $job = $disks->add($server, (int) $data['size_gb'], $request->user());

        return response()->json(['data' => $this->diskList($server->fresh()), 'job_id' => $job->id], 202);
    }

    /** Powiększenie dysku — dev: vda (główny) albo vdb–vdh. */
    public function growDisk(Request $request, Server $server, string $dev, ServerDisks $disks): JsonResponse
    {
        $data = $request->validate(['size_gb' => ['required', 'integer', 'min:1', 'max:65536']]);
        $job = $disks->grow($server, $this->diskByDev($server, $dev), (int) $data['size_gb'], $request->user());

        return response()->json(['data' => $this->diskList($server->fresh()), 'job_id' => $job->id], 202);
    }

    public function removeDisk(Request $request, Server $server, string $dev, ServerDisks $disks): JsonResponse
    {
        $disk = $this->diskByDev($server, $dev);
        abort_if($disk === null, 422, __('Dysku głównego nie można usunąć.'));
        $job = $disks->remove($disk, $request->user());

        return response()->json(['data' => $this->diskList($server->fresh()), 'job_id' => $job->id], 202);
    }

    public function hardware(Server $server): JsonResponse
    {
        return response()->json(['data' => ServerHardware::resolve($server)]);
    }

    public function updateHardware(Request $request, Server $server, ServerHardware $hardware): JsonResponse
    {
        $data = $request->validate([
            'firmware' => ['sometimes', 'nullable', Rule::in(ServerHardware::FIRMWARES)],
            'tpm' => ['sometimes', 'nullable', 'boolean'],
            'disk_bus' => ['sometimes', 'nullable', Rule::in(ServerHardware::DISK_BUSES)],
            'io_mode' => ['sometimes', 'nullable', Rule::in(ServerHardware::IO_MODES)],
            'cpu_model' => ['sometimes', 'nullable', Rule::in(ServerHardware::CPU_MODELS)],
        ]);
        $settings = [];
        foreach ($data as $key => $value) {
            $settings['hw_'.$key] = $key === 'tpm' && $value !== null ? (bool) $value : $value;
        }
        $job = $hardware->update($server, $settings, $request->user());

        return response()->json(['data' => ServerHardware::resolve($server->fresh()), 'job_id' => $job->id,
            'message' => __('Zmiany działają po wyłączeniu i włączeniu maszyny.')], 202);
    }

    private function diskByDev(Server $server, string $dev): ?ServerDisk
    {
        if ($dev === 'vda') {
            return null;
        }
        abort_unless(preg_match('/^vd([b-h])$/', $dev, $m), 404);

        return $server->disks()->where('slot', strpos('abcdefgh', $m[1]))->firstOrFail();
    }

    /** @return list<array{dev: string, size_gb: int, primary: bool}> */
    private function diskList(Server $server): array
    {
        return [['dev' => 'vda', 'size_gb' => (int) $server->disk_gb, 'primary' => true],
            ...$server->disks()->get()->map(fn (ServerDisk $d) => ['dev' => $d->dev(), 'size_gb' => $d->size_gb, 'primary' => false])->all()];
    }

    public function unsuspend(Request $request, Server $server): JsonResponse
    {
        $job = $this->provisioner->unsuspend($server, $request->user());

        return response()->json([
            'message' => __('Maszyna została odwieszona i jest uruchamiana.'),
            'job_id' => $job->id,
        ], 202);
    }

    /**
     * Historia operacji na maszynie — pierwsze miejsce, do którego zagląda
     * wsparcie, gdy klient zgłasza, że „coś się stało z serwerem".
     */
    public function jobs(Server $server): JsonResponse
    {
        return response()->json([
            'data' => $server->jobs()->with('user:id,email')->limit(50)->get()->map(fn ($job) => [
                'id' => $job->id,
                'action' => $job->action,
                'status' => $job->status,
                'error' => $job->error,
                'actor' => $job->user?->email ?? 'system',
                'created_at' => $job->created_at,
                'finished_at' => $job->finished_at,
            ]),
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->with('actor:id,email')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('actor_id', $request->integer('actor_id')))
            ->latest()
            ->paginate(100);

        return response()->json($logs->through(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'actor' => $log->actor?->email ?? $log->actor_label,
            'subject' => $log->subject_type ? class_basename($log->subject_type).'#'.$log->subject_id : null,
            'meta' => $log->meta,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at,
        ]));
    }
}
