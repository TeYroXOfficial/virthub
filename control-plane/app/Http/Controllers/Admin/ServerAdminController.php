<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServerResource;
use App\Models\AuditLog;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            'message' => 'Maszyna została zawieszona i jest zatrzymywana.',
            'job_id' => $job->id,
        ], 202);
    }

    public function unsuspend(Request $request, Server $server): JsonResponse
    {
        $job = $this->provisioner->unsuspend($server, $request->user());

        return response()->json([
            'message' => 'Maszyna została odwieszona i jest uruchamiana.',
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
