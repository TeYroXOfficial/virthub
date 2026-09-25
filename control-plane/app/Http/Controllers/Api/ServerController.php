<?php

namespace App\Http\Controllers\Api;

use App\Domain\Agent\AgentException;
use App\Domain\Console\ConsoleSessions;
use App\Domain\Metrics\ServerMetrics;
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
        $this->authorize('power', $server);

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
        $this->authorize('rebuild', $server);

        $validated = $request->validate([
            'template' => ['required', Rule::exists(OsTemplate::class, 'id')->where('is_active', true)],
            'ssh_keys' => ['array', 'max:10'],
            'ssh_keys.*' => ['string', 'max:1000', new \App\Rules\SshPublicKey],
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
        $this->authorize('resize', $server);

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

    /** Historia zużycia: ?range=hour|day|week (domyślnie doba). */
    public function metrics(Request $request, Server $server, ServerMetrics $metrics): JsonResponse
    {
        $this->authorize('view', $server);

        $range = $request->string('range', 'day')->value();
        abort_unless(array_key_exists($range, ServerMetrics::RANGES), 422, 'Zakres musi być jednym z: hour, day, week.');

        return response()->json($metrics->history($server, $range));
    }

    /** Bieżące zużycie prosto z agenta — do podglądu na żywo. */
    public function liveMetrics(Request $request, Server $server, ServerMetrics $metrics): JsonResponse
    {
        $this->authorize('view', $server);

        if (! $server->isRunning() || $server->hypervisor === null || blank($server->agent_uuid)) {
            return response()->json(['message' => 'Podgląd na żywo jest dostępny tylko dla działającej maszyny.'], 409);
        }

        try {
            return response()->json($metrics->live($server));
        } catch (AgentException $e) {
            return response()->json(['message' => 'Węzeł maszyny nie odpowiada.'], 503);
        }
    }

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
        $this->authorize('console', $server);

        if (! $server->isRunning()) {
            return response()->json([
                'message' => 'Konsola jest dostępna tylko dla działającej maszyny.',
            ], 409);
        }

        $token = app(ConsoleSessions::class)->issueTicket($server, $request->user());

        return response()->json([
            'token' => $token,
            'expires_in' => 60,
            'url' => route('console.show', ['token' => $token]),
        ]);
    }
}
