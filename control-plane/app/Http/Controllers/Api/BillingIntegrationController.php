<?php

namespace App\Http\Controllers\Api;

use App\Domain\Console\ConsoleSessions;
use App\Domain\Metrics\ServerMetrics;
use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServerResource;
use App\Models\AuditLog;
use App\Models\HypervisorGroup;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use App\Rules\Hostname;
use App\Rules\SshPublicKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Integracja z zewnętrznym systemem billingowym.
 *
 * Panel nie wystawia faktur ani nie zna cen — przyjmuje polecenia od systemu,
 * który to robi. Kluczowe jest `billing_reference`: identyfikator usługi po
 * stronie billingu, po którym odnajdujemy maszynę bez znajomości naszych ID.
 *
 * Każde wywołanie jest idempotentne po tym identyfikatorze: ponowione żądanie
 * provisioningu (a webhooki bywają dublowane) nie utworzy drugiej maszyny.
 */
class BillingIntegrationController extends Controller
{
    public function __construct(private readonly ServerProvisioner $provisioner) {}

    public function provision(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'billing_reference' => ['required', 'string', 'max:100'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_name' => ['required', 'string', 'max:100'],
            'customer_reference' => ['nullable', 'string', 'max:100'],
            'package' => ['required', Rule::exists(VpsPackage::class, 'slug')],
            'template' => ['required', Rule::exists(OsTemplate::class, 'id')],
            'hostname' => ['required', 'string', 'max:253', new Hostname],
            'ssh_keys' => ['array', 'max:10'],
            'ssh_keys.*' => ['string', 'max:1000', new SshPublicKey],
            'location' => ['nullable', 'integer', Rule::exists(HypervisorGroup::class, 'id')],
        ]);

        $existing = Server::withTrashed()
            ->where('billing_reference', $validated['billing_reference'])
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => __('Usługa o tym identyfikatorze już istnieje.'),
                'server_id' => $existing->id,
                'state' => $existing->state->value,
                'idempotent' => true,
            ], 200);
        }

        $user = $this->resolveCustomer($validated);

        $server = $this->provisioner->order(
            user: $user,
            package: VpsPackage::where('slug', $validated['package'])->firstOrFail(),
            template: OsTemplate::findOrFail($validated['template']),
            hostname: Str::lower($validated['hostname']),
            sshKeys: $validated['ssh_keys'] ?? [],
            billingReference: $validated['billing_reference'],
            location: isset($validated['location']) ? HypervisorGroup::find($validated['location']) : null,
        );

        return response()->json([
            'message' => __('Maszyna jest tworzona.'),
            'server_id' => $server->id,
            'customer_id' => $user->id,
            'state' => $server->state->value,
        ], 201);
    }

    public function suspend(Request $request, string $reference): JsonResponse
    {
        $server = $this->findByReference($reference);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($server->isSuspended()) {
            return response()->json(['message' => __('Usługa jest już zawieszona.'), 'idempotent' => true]);
        }

        $this->provisioner->suspend(
            $server,
            $validated['reason'] ?? 'Zawieszenie zlecone przez system rozliczeniowy.',
        );

        return response()->json(['message' => __('Usługa została zawieszona.')], 202);
    }

    public function unsuspend(string $reference): JsonResponse
    {
        $server = $this->findByReference($reference);

        if (! $server->isSuspended()) {
            return response()->json(['message' => __('Usługa nie jest zawieszona.'), 'idempotent' => true]);
        }

        $this->provisioner->unsuspend($server);

        return response()->json(['message' => __('Usługa została odwieszona.')], 202);
    }

    public function terminate(Request $request, string $reference): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']]);

        $server = $this->findByReference($reference);
        $this->provisioner->destroy($server);

        return response()->json(['message' => __('Usługa została zgłoszona do usunięcia.')], 202);
    }

    /**
     * Bilet logowania jednorazowego. Klient klika „Zarządzaj serwerem" w panelu
     * billingowym i trafia zalogowany prosto do widoku swojej maszyny — bez
     * drugiego hasła do zapamiętania.
     */
    public function ssoToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_email' => ['required', 'email'],
            'server_id' => ['nullable', 'integer'],
        ]);

        $user = User::where('email', $validated['customer_email'])->first();

        if ($user === null) {
            return response()->json(['message' => __('Nie znaleziono klienta o tym adresie e-mail.')], 404);
        }

        $token = Str::random(64);

        // Krótki czas życia: bilet ma przetrwać przekierowanie przeglądarki,
        // nie leżeć w historii adresów jako wieczna furtka do konta.
        Cache::put("sso:{$token}", [
            'user_id' => $user->id,
            'server_id' => $validated['server_id'] ?? null,
        ], now()->addSeconds(30));

        AuditLog::record('billing.sso_issued', $user, [], null);

        return response()->json([
            'url' => route('sso.consume', ['token' => $token]),
            'expires_in' => 30,
        ]);
    }

    // --- sterowanie usługą z systemu rozliczeniowego ----------------------------

    /** Pakiety, systemy i lokalizacje — do konfiguracji produktu w module billingu. */
    public function catalog(): JsonResponse
    {
        return response()->json([
            'packages' => VpsPackage::query()->where('is_active', true)->orderBy('vcpu')->orderBy('ram_mb')->get()
                ->map(fn (VpsPackage $p) => $p->only(['slug', 'name', 'vcpu', 'ram_mb', 'disk_gb', 'bandwidth_gb', 'ip_count', 'ipv6_count']))->values(),
            'templates' => OsTemplate::query()->where('is_active', true)->orderBy('name')->get()
                ->filter(fn (OsTemplate $t) => $t->isSelfService())
                ->map(fn (OsTemplate $t) => ['id' => $t->id, 'name' => $t->name, 'family' => $t->family,
                    'virtualization' => $t->virtualization->value, 'min_disk_gb' => $t->min_disk_gb])->values(),
            'locations' => HypervisorGroup::query()->where('is_public', true)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (HypervisorGroup $g) => ['id' => $g->id, 'name' => $g->publicName()])->values(),
        ]);
    }

    /** Stan usługi do panelu klienta w billingu: zasoby, adresy, system, transfer. */
    public function show(Request $request, string $reference): JsonResponse
    {
        $server = $this->findByReference($reference)->load(['package', 'template', 'ipAddresses.pool', 'hypervisor']);

        return response()->json([
            'data' => ServerResource::make($server)->resolve($request) + [
                'billing_reference' => $server->billing_reference,
                'busy' => $server->jobs()->whereIn('status', [ServerJob::STATUS_QUEUED, ServerJob::STATUS_RUNNING])->exists(),
            ],
        ]);
    }

    public function power(Request $request, string $reference): JsonResponse
    {
        $server = $this->findByReference($reference);
        $validated = $request->validate(['action' => ['required', Rule::in(['start', 'stop', 'reboot', 'force-off'])]]);
        $job = $this->provisioner->power($server, $validated['action'], $request->user());

        return response()->json(['message' => __('Polecenie zostało przekazane do hypervisora.'), 'job_id' => $job->id], 202);
    }

    public function rebuild(Request $request, string $reference): JsonResponse
    {
        $server = $this->findByReference($reference);
        $validated = $request->validate([
            'template' => ['required', Rule::exists(OsTemplate::class, 'id')->where('is_active', true)],
            'ssh_keys' => ['array', 'max:10'],
            'ssh_keys.*' => ['string', 'max:1000', new SshPublicKey],
            'confirm' => ['accepted'],
        ]);
        $job = $this->provisioner->rebuild($server, OsTemplate::findOrFail($validated['template']), $validated['ssh_keys'] ?? [], $request->user());

        return response()->json(['message' => __('Rozpoczęto ponowną instalację systemu. Wszystkie dane na dysku zostaną utracone.'), 'job_id' => $job->id], 202);
    }

    /** Zmiana pakietu (upgrade/downgrade w billingu). Maszyna musi być zatrzymana. */
    public function resize(Request $request, string $reference): JsonResponse
    {
        $server = $this->findByReference($reference);
        $validated = $request->validate(['package' => ['required', Rule::exists(VpsPackage::class, 'slug')]]);
        $package = VpsPackage::where('slug', $validated['package'])->firstOrFail();
        if ($server->vps_package_id === $package->id) {
            return response()->json(['message' => __('Usługa ma już ten pakiet.'), 'idempotent' => true]);
        }
        $job = $this->provisioner->resize($server, $package, $request->user());

        return response()->json(['message' => __('Zmiana pakietu została zlecona.'), 'job_id' => $job->id], 202);
    }

    /** Nowe hasło roota — po wykonaniu zlecenia odbierasz je przez /credentials. */
    public function resetPassword(Request $request, string $reference): JsonResponse
    {
        $job = $this->provisioner->resetPassword($this->findByReference($reference), $request->user());

        return response()->json(['message' => __('Hasło zostanie zmienione w ciągu kilku sekund — odbierz je przez /credentials.'), 'job_id' => $job->id], 202);
    }

    /** Hasło roota (początkowe, po reinstalacji albo resecie) — zwracane jeden raz. */
    public function credentials(string $reference): JsonResponse
    {
        $server = $this->findByReference($reference);
        $password = $server->consumeRootPassword();
        if ($password !== null) {
            AuditLog::record('billing.credentials', $server);
        }

        return response()->json(['username' => $server->osFamily() === 'windows' ? 'Administrator' : 'root', 'password' => $password, 'available' => $password !== null]);
    }

    /** Konsola w przeglądarce (bilet jednorazowy na koncie właściciela maszyny). */
    public function console(string $reference): JsonResponse
    {
        $server = $this->findByReference($reference);
        if (! $server->isRunning()) {
            return response()->json(['message' => __('Konsola jest dostępna tylko dla działającej maszyny.')], 409);
        }
        $token = app(ConsoleSessions::class)->issueTicket($server, $server->user);

        return response()->json(['url' => route('console.show', ['token' => $token]), 'expires_in' => 60]);
    }

    /** Logowanie klienta prosto na stronę maszyny w panelu. */
    public function serviceSso(string $reference): JsonResponse
    {
        $server = $this->findByReference($reference);
        $token = Str::random(64);
        Cache::put("sso:{$token}", ['user_id' => $server->user_id, 'server_id' => $server->id], now()->addSeconds(30));
        AuditLog::record('billing.sso_issued', $server->user, ['server_id' => $server->id], null);

        return response()->json(['url' => route('sso.consume', ['token' => $token]), 'expires_in' => 30]);
    }

    /** Historia zużycia: ?range=hour|day|week. */
    public function metrics(Request $request, string $reference, ServerMetrics $metrics): JsonResponse
    {
        $range = $request->string('range', 'day')->value();
        abort_unless(array_key_exists($range, ServerMetrics::RANGES), 422, __('Zakres musi być jednym z: hour, day, week.'));

        return response()->json($metrics->history($this->findByReference($reference), $range));
    }

    // --- pomocnicze ---------------------------------------------------------

    private function findByReference(string $reference): Server
    {
        return Server::where('billing_reference', $reference)->firstOrFail();
    }

    private function resolveCustomer(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::where('email', $data['customer_email'])->first();

            if ($user !== null) {
                return $user;
            }

            // Konto zakładane przez billing nie ma hasła do logowania —
            // klient wchodzi przez SSO albo ustawia hasło resetem.
            return User::create([
                'name' => $data['customer_name'],
                'email' => $data['customer_email'],
                'password' => Str::random(64),
                'role' => User::ROLE_CUSTOMER,
                'billing_reference' => $data['customer_reference'] ?? null,
            ]);
        });
    }
}
