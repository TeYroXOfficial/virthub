<?php

namespace App\Http\Controllers\Api;

use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SnapshotController extends Controller
{
    /** Limit chroni miejsce na hypervisorze — snapshot waży tyle, co dysk maszyny. */
    private const MAX_PER_SERVER = 5;

    public function __construct(private readonly ServerProvisioner $provisioner) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        return response()->json([
            'data' => $server->backups()->latest()->get()->map(fn (Backup $backup) => [
                'id' => $backup->id,
                'name' => $backup->name,
                'status' => $backup->status,
                'size_mb' => $backup->size_mb,
                'created_at' => $backup->created_at,
                'restorable' => $backup->isRestorable(),
            ]),
            'limit' => self::MAX_PER_SERVER,
            'used' => $server->backups()->count(),
        ]);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize('snapshots', $server);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_.-]+$/'],
        ]);

        if ($server->backups()->count() >= self::MAX_PER_SERVER) {
            return response()->json([
                'message' => 'Osiągnięto limit '.self::MAX_PER_SERVER
                    .' kopii dla tej maszyny. Usuń starą kopię, aby zrobić nową.',
            ], 422);
        }

        if ($server->backups()->where('name', $validated['name'])->exists()) {
            return response()->json([
                'message' => 'Kopia o tej nazwie już istnieje.',
            ], 422);
        }

        $job = $this->provisioner->snapshot($server, $validated['name'], $request->user());

        return response()->json([
            'message' => 'Tworzenie kopii zostało zlecone.',
            'job_id' => $job->id,
        ], 202);
    }

    public function restore(Request $request, Server $server, Backup $backup): JsonResponse
    {
        $this->authorize('restoreSnapshot', $server);

        if ($backup->server_id !== $server->id) {
            abort(404);
        }

        $request->validate(['confirm' => ['accepted']]);

        $job = $this->provisioner->restore($server, $backup, $request->user());

        return response()->json([
            'message' => 'Przywracanie kopii zostało zlecone. Maszyna zostanie zrestartowana.',
            'job_id' => $job->id,
        ], 202);
    }

    public function destroy(Request $request, Server $server, Backup $backup): JsonResponse
    {
        $this->authorize('snapshots', $server);

        if ($backup->server_id !== $server->id) {
            abort(404);
        }

        $backup->delete();

        return response()->json(['message' => 'Kopia została usunięta z listy.']);
    }
}
