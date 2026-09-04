<?php

namespace App\Http\Controllers\Api;

use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Models\VpsPackage;
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
            'hostname' => ['required', 'string', 'max:253'],
            'ssh_keys' => ['array', 'max:10'],
            'ssh_keys.*' => ['string', 'max:1000'],
        ]);

        $existing = Server::withTrashed()
            ->where('billing_reference', $validated['billing_reference'])
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => 'Usługa o tym identyfikatorze już istnieje.',
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
        );

        return response()->json([
            'message' => 'Maszyna jest tworzona.',
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
            return response()->json(['message' => 'Usługa jest już zawieszona.', 'idempotent' => true]);
        }

        $this->provisioner->suspend(
            $server,
            $validated['reason'] ?? 'Zawieszenie zlecone przez system rozliczeniowy.',
        );

        return response()->json(['message' => 'Usługa została zawieszona.'], 202);
    }

    public function unsuspend(string $reference): JsonResponse
    {
        $server = $this->findByReference($reference);

        if (! $server->isSuspended()) {
            return response()->json(['message' => 'Usługa nie jest zawieszona.', 'idempotent' => true]);
        }

        $this->provisioner->unsuspend($server);

        return response()->json(['message' => 'Usługa została odwieszona.'], 202);
    }

    public function terminate(Request $request, string $reference): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']]);

        $server = $this->findByReference($reference);
        $this->provisioner->destroy($server);

        return response()->json(['message' => 'Usługa została zgłoszona do usunięcia.'], 202);
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
            return response()->json(['message' => 'Nie znaleziono klienta o tym adresie e-mail.'], 404);
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
