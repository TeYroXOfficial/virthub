<?php

namespace App\Http\Controllers\Api;

use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderServerRequest;
use App\Http\Resources\ServerResource;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\VpsPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    public function __construct(private readonly ServerProvisioner $provisioner) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Server::class);

        $servers = Server::query()
            ->ownedBy($request->user())
            ->with(['package', 'template', 'ipAddresses.pool', 'hypervisor'])
            ->latest()
            ->paginate(25);

        return ServerResource::collection($servers);
    }

    public function store(OrderServerRequest $request): JsonResponse
    {
        $this->authorize('create', Server::class);

        $server = $this->provisioner->order(
            user: $request->user(),
            package: $request->package(),
            template: $request->template(),
            hostname: $request->string('hostname')->lower()->value(),
            sshKeys: $request->sshKeys(),
            label: $request->input('label'),
        );

        return ServerResource::make($server->load(['package', 'template', 'ipAddresses.pool']))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Server $server): ServerResource
    {
        $this->authorize('view', $server);

        return ServerResource::make(
            $server->load(['package', 'template', 'ipAddresses.pool', 'hypervisor'])
        );
    }

    public function destroy(Request $request, Server $server): JsonResponse
    {
        $this->authorize('destroy', $server);

        $job = $this->provisioner->destroy($server, $request->user());

        return response()->json([
            'message' => 'Maszyna została zgłoszona do usunięcia.',
            'job_id' => $job->id,
        ], 202);
    }

    // --- sterowanie ---------------------------------------------------------

    public function power(Request $request, Server $server): JsonResponse
    {
        $this->authorize('operate', $server);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['start', 'stop', 'reboot', 'force-off'])],
        ]);

        $job = $this->provisioner->power($server, $validated['action'], $request->user());

        return response()->json([
            'message' => 'Polecenie zostało przekazane do hypervisora.',
            'job_id' => $job->id,
        ], 202);
    }

    public function rebuild(Request $request, Server $server): JsonResponse
    {
        $this->authorize('destroy', $server);

        $validated = $request->validate([
            'template' => ['required', Rule::exists(OsTemplate::class, 'id')->where('is_active', true)],
            'ssh_keys' => ['array', 'max:10'],
            'ssh_keys.*' => ['string', 'max:1000'],
            // Przebudowa kasuje dysk bezpowrotnie — wymagamy świadomego
            // potwierdzenia, żeby nie dało się jej wywołać przypadkiem.
            'confirm' => ['accepted'],
        ]);

        $job = $this->provisioner->rebuild(
            $server,
            OsTemplate::findOrFail($validated['template']),
            $validated['ssh_keys'] ?? [],
            $request->user(),
        );

        return response()->json([
            'message' => 'Rozpoczęto ponowną instalację systemu. Wszystkie dane na dysku zostaną utracone.',
            'job_id' => $job->id,
        ], 202);
    }

    public function resize(Request $request, Server $server): JsonResponse
    {
        $this->authorize('operate', $server);

        $validated = $request->validate([
            'package' => ['required', Rule::exists(VpsPackage::class, 'slug')->where('is_active', true)],
        ]);

        $job = $this->provisioner->resize(
            $server,
            VpsPackage::where('slug', $validated['package'])->firstOrFail(),
            $request->user(),
        );

        return response()->json([
            'message' => 'Zmiana pakietu została zlecona.',
            'job_id' => $job->id,
        ], 202);
    }

    // --- odczyty ------------------------------------------------------------

    public function stats(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        $hours = min(720, max(1, $request->integer('hours', 24)));

        $metrics = ServerMetric::query()
            ->where('server_id', $server->id)
            ->where('sampled_at', '>=', now()->subHours($hours))
            ->orderBy('sampled_at')
            ->get(['sampled_at', 'cpu_percent', 'ram_used_mb', 'net_rx_bytes', 'net_tx_bytes']);

        return response()->json([
            'server_id' => $server->id,
            'window_hours' => $hours,
            'samples' => $metrics,
        ]);
    }

    /**
     * Hasło root pokazujemy jeden raz. Kolejne wywołanie zwraca pustkę — klient
     * ma je zapisać w swoim menedżerze haseł albo zresetować maszynę.
     */
    public function credentials(Request $request, Server $server): JsonResponse
    {
        $this->authorize('operate', $server);

        $password = $server->consumeRootPassword();

        return response()->json([
            'username' => 'root',
            'password' => $password,
            'available' => $password !== null,
            'note' => $password === null
                ? 'Hasło początkowe zostało już wyświetlone. Zresetuj je z poziomu maszyny albo przebuduj system.'
                : 'Zapisz je teraz — nie zobaczysz go ponownie.',
        ]);
    }

    /**
     * Jednorazowy bilet do konsoli. Sam adres i port hypervisora nigdy nie
     * trafiają do przeglądarki — proxy konsoli wymienia bilet na tunel.
     */
    public function consoleToken(Request $request, Server $server): JsonResponse
    {
        $this->authorize('operate', $server);

        if (! $server->isRunning()) {
            return response()->json([
                'message' => 'Konsola jest dostępna tylko dla działającej maszyny.',
            ], 409);
        }

        $token = Str::random(48);

        Cache::put("console:{$token}", [
            'server_id' => $server->id,
            'user_id' => $request->user()->id,
        ], now()->addSeconds(60));

        return response()->json([
            'token' => $token,
            'expires_in' => 60,
            'url' => route('console.show', ['token' => $token]),
        ]);
    }
}
