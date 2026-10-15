<?php

namespace App\Http\Controllers\Api;

use App\Domain\Provisioning\GuestSettings;
use App\Domain\Provisioning\RescueMode;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Rules\SshPublicKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tryb ratunkowy, dysk cloud-init i nazwa hosta przez API klienta. */
class GuestSettingsController extends Controller
{
    public function rescue(Server $server): JsonResponse
    {
        $this->authorize('rebuild', $server);

        return response()->json($this->rescueState($server));
    }

    public function startRescue(Request $request, Server $server, RescueMode $rescue): JsonResponse
    {
        $this->authorize('rebuild', $server);
        $request->validate(['ssh_keys' => ['array', 'max:10'], 'ssh_keys.*' => ['string', 'max:1000', new SshPublicKey]]);
        $job = $rescue->start($server, $request->user(), $request->input('ssh_keys', []));

        return response()->json(['job_id' => $job->id, 'status' => $job->status, 'message' => __('Maszyna uruchamia się w trybie ratunkowym.')], 202);
    }

    public function stopRescue(Request $request, Server $server, RescueMode $rescue): JsonResponse
    {
        $this->authorize('rebuild', $server);
        $job = $rescue->stop($server, $request->user());

        return response()->json(['job_id' => $job->id, 'status' => $job->status], 202);
    }

    public function cloudInit(Request $request, Server $server, GuestSettings $settings): JsonResponse
    {
        $this->authorize('iso', $server);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $job = $settings->cloudInit($server, (bool) $data['enabled'], $request->user());

        return response()->json(['job_id' => $job->id, 'status' => $job->status, 'message' => __('Zmiana działa od następnego uruchomienia maszyny.')], 202);
    }

    public function hostname(Request $request, Server $server, GuestSettings $settings): JsonResponse
    {
        $this->authorize('power', $server);
        $data = $request->validate(['hostname' => ['required', 'string', 'max:253']]);
        $job = $settings->hostname($server, $data['hostname'], $request->user());

        return response()->json(['hostname' => $server->fresh()->hostname, 'job_id' => $job?->id], 200);
    }

    /** @return array<string, mixed> */
    private function rescueState(Server $server): array
    {
        return [
            'active' => $server->inRescue(),
            'since' => $server->rescue_at?->toIso8601String(),
            'username' => $server->inRescue() ? 'root' : null,
            'password' => $server->inRescue() ? $server->rescue_password : null,
            'address' => $server->inRescue() ? $server->primaryIp()?->address : null,
            'disk' => $server->inRescue() ? '/dev/vdi' : null,
        ];
    }
}
