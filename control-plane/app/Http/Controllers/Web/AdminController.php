<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Provisioning\HypervisorEnrollment;
use App\Domain\Provisioning\IpAllocator;
use App\Enums\ServerState;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function __construct(private readonly HypervisorEnrollment $enrollment) {}

    // --- przegląd -----------------------------------------------------------

    public function index(): View
    {
        $hypervisors = Hypervisor::query()->withCount('servers')->orderBy('name')->get();

        return view('panel.admin.index', [
            'hypervisors' => $hypervisors,
            'serversTotal' => Server::count(),
            'serversRunning' => Server::where('state', ServerState::Running->value)->count(),
            'serversBroken' => Server::whereIn('state', [
                ServerState::Error->value,
                ServerState::Suspended->value,
            ])->count(),
            'customers' => User::where('role', User::ROLE_CUSTOMER)->count(),
            'addressesFree' => IpAddress::assignable()->count(),
            'addressesTotal' => IpAddress::count(),
            'recentLogs' => AuditLog::with('actor:id,email')->latest()->limit(12)->get(),
        ]);
    }

    // --- hypervisory --------------------------------------------------------

    public function hypervisors(): View
    {
        return view('panel.admin.hypervisors', [
            'hypervisors' => Hypervisor::query()->withCount('servers')->orderBy('name')->get(),
        ]);
    }

    /**
     * Dodanie węzła nie wymaga podania adresu ani zasobów — węzeł zgłosi je sam
     * przy rejestracji. Administrator podaje wyłącznie nazwę i dostaje polecenie
     * do wklejenia na nowym serwerze.
     */
    public function storeHypervisor(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:hypervisors,name'],
        ]);

        $hypervisor = Hypervisor::create([
            'name' => $validated['name'],
            'hostname' => 'oczekuje-na-rejestracje',
            'agent_token' => Str::random(64),      // podmieniane przy rejestracji
            'callback_secret' => Str::random(64),
            'status' => Hypervisor::STATUS_OFFLINE,
            'accepts_new_servers' => true,
            'bridge' => 'br0',
        ]);

        $token = $this->enrollment->issueToken($hypervisor);

        // Polecenie leci przez sesję, a nie przez adres — bilet nie ma czego
        // szukać w historii przeglądarki ani w logach serwera.
        return redirect()
            ->route('panel.admin.hypervisors')
            ->with('enrollment', [
                'hypervisor' => $hypervisor->name,
                'command' => $this->enrollmentCommand($token),
                'expires_at' => $hypervisor->enrollment_expires_at,
            ]);
    }

    public function regenerateEnrollment(Hypervisor $hypervisor): RedirectResponse
    {
        if ($hypervisor->enrolled_at !== null) {
            return back()->withErrors([
                'enrollment' => "Węzeł {$hypervisor->name} jest już zarejestrowany. "
                    .'Aby przeinstalować agenta, usuń węzeł i dodaj go ponownie.',
            ]);
        }

        $token = $this->enrollment->issueToken($hypervisor);

        return back()->with('enrollment', [
            'hypervisor' => $hypervisor->name,
            'command' => $this->enrollmentCommand($token),
            'expires_at' => $hypervisor->fresh()->enrollment_expires_at,
        ]);
    }

    public function updateHypervisor(Request $request, Hypervisor $hypervisor): RedirectResponse
    {
        $validated = $request->validate([
            'cpu_cores_total' => ['required', 'integer', 'min:1'],
            'ram_mb_total' => ['required', 'integer', 'min:1024'],
            'disk_gb_total' => ['required', 'integer', 'min:10'],
            'bridge' => ['required', 'string', 'max:32'],
            'accepts_new_servers' => ['boolean'],
            'status' => ['required', Rule::in([
                Hypervisor::STATUS_ONLINE,
                Hypervisor::STATUS_OFFLINE,
                Hypervisor::STATUS_MAINTENANCE,
            ])],
        ]);

        $hypervisor->update([
            ...$validated,
            'accepts_new_servers' => $request->boolean('accepts_new_servers'),
        ]);

        AuditLog::record('hypervisor.updated', $hypervisor, $validated);

        return back()->with('status', "Zapisano ustawienia węzła {$hypervisor->name}.");
    }

    public function checkHypervisor(Hypervisor $hypervisor): RedirectResponse
    {
        if ($hypervisor->agent_url === null) {
            return back()->withErrors([
                'health' => "Węzeł {$hypervisor->name} nie został jeszcze zarejestrowany.",
            ]);
        }

        try {
            $health = (new \App\Domain\Agent\AgentClient($hypervisor))->health();
        } catch (AgentException $e) {
            $hypervisor->forceFill(['status' => Hypervisor::STATUS_OFFLINE])->save();

            return back()->withErrors(['health' => $e->getMessage()]);
        }

        $hypervisor->forceFill([
            'status' => Hypervisor::STATUS_ONLINE,
            'last_seen_at' => now(),
            'last_health' => $health,
        ])->save();

        return back()->with('status', sprintf(
            'Węzeł %s odpowiada: sterownik %s, %d maszyn, wolne %d MB RAM.',
            $hypervisor->name,
            $health['driver'] ?? '?',
            $health['running_vms'] ?? 0,
            $health['ram_mb_free'] ?? 0,
        ));
    }

    public function destroyHypervisor(Hypervisor $hypervisor): RedirectResponse
    {
        if ($hypervisor->servers()->exists()) {
            return back()->withErrors([
                'delete' => "Na węźle {$hypervisor->name} są maszyny. Usuń je najpierw — "
                    .'skasowanie węzła zostawiłoby je bez opieki.',
            ]);
        }

        AuditLog::record('hypervisor.deleted', $hypervisor, ['name' => $hypervisor->name]);
        $hypervisor->delete();

        return back()->with('status', 'Węzeł został usunięty.');
    }

    // --- pakiety ------------------------------------------------------------

    public function packages(): View
    {
        return view('panel.admin.packages', [
            'packages' => VpsPackage::query()->withCount('servers')->orderBy('vcpu')->get(),
        ]);
    }

    public function storePackage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'vcpu' => ['required', 'integer', 'min:1', 'max:128'],
            'ram_mb' => ['required', 'integer', 'min:512'],
            'disk_gb' => ['required', 'integer', 'min:5'],
            'bandwidth_gb' => ['required', 'integer', 'min:0'],
            'ip_count' => ['required', 'integer', 'min:1', 'max:16'],
            'price_hint' => ['nullable', 'numeric', 'min:0'],
        ]);

        $package = VpsPackage::create([
            ...$validated,
            'slug' => Str::slug($validated['name']),
            'price_hint_cents' => isset($validated['price_hint'])
                ? (int) round($validated['price_hint'] * 100)
                : null,
            'currency' => 'PLN',
            'is_active' => true,
        ]);

        AuditLog::record('package.created', $package, ['slug' => $package->slug]);

        return back()->with('status', "Pakiet {$package->name} został dodany.");
    }

    public function togglePackage(VpsPackage $package): RedirectResponse
    {
        $package->update(['is_active' => ! $package->is_active]);

        return back()->with('status', $package->is_active
            ? "Pakiet {$package->name} jest znowu dostępny w sprzedaży."
            : "Pakiet {$package->name} został wycofany ze sprzedaży. Istniejące maszyny działają dalej.");
    }

    // --- szablony -----------------------------------------------------------

    public function templates(): View
    {
        return view('panel.admin.templates', [
            'templates' => OsTemplate::query()->orderBy('family')->orderByDesc('version')->get(),
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'family' => ['required', Rule::in(['ubuntu', 'debian', 'almalinux', 'rocky', 'fedora', 'windows'])],
            'version' => ['required', 'string', 'max:32'],
            // Sama nazwa pliku — układ katalogów należy do agenta, a wartość
            // ze ścieżką pozwoliłaby sięgnąć poza katalog szablonów.
            'image_file' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'min_disk_gb' => ['required', 'integer', 'min:1'],
        ], [
            'image_file.regex' => 'Podaj samą nazwę pliku obrazu, bez ścieżki (np. ubuntu-24.04.qcow2).',
        ]);

        $template = OsTemplate::create([
            ...$validated,
            'cloud_init_support' => $validated['family'] !== 'windows',
            'is_active' => true,
        ]);

        AuditLog::record('template.created', $template, ['name' => $template->name]);

        return back()->with('status', "Szablon {$template->name} został dodany. "
            ."Upewnij się, że plik {$template->image_file} leży w katalogu szablonów na każdym węźle.");
    }

    public function toggleTemplate(OsTemplate $template): RedirectResponse
    {
        $template->update(['is_active' => ! $template->is_active]);

        return back()->with('status', $template->is_active
            ? "Szablon {$template->name} jest znowu dostępny."
            : "Szablon {$template->name} został wyłączony.");
    }

    // --- pule adresów -------------------------------------------------------

    public function ipPools(): View
    {
        return view('panel.admin.ip-pools', [
            'pools' => IpPool::query()->with('hypervisor')->withCount([
                'addresses',
                'addresses as assigned_count' => fn ($q) => $q->whereNotNull('server_id'),
                'addresses as reserved_count' => fn ($q) => $q->where('is_reserved', true),
            ])->get(),
            'hypervisors' => Hypervisor::query()->orderBy('name')->get(),
        ]);
    }

    public function storeIpPool(Request $request, IpAllocator $allocator): RedirectResponse
    {
        $validated = $request->validate([
            'hypervisor_id' => ['required', 'exists:hypervisors,id'],
            'name' => ['required', 'string', 'max:100'],
            'cidr' => ['required', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/'],
            'gateway' => ['required', 'ip'],
            'prefix' => ['required', 'integer', 'min:1', 'max:32'],
            'range_from' => ['nullable', 'ip'],
            'range_to' => ['nullable', 'ip'],
            'nameservers' => ['nullable', 'string', 'max:200'],
        ], [
            'cidr.regex' => 'Podaj podsieć w notacji CIDR, np. 203.0.113.0/24.',
        ]);

        $pool = IpPool::create([
            'hypervisor_id' => $validated['hypervisor_id'],
            'name' => $validated['name'],
            'cidr' => $validated['cidr'],
            'version' => 4,
            'gateway' => $validated['gateway'],
            'prefix' => $validated['prefix'],
            'nameservers' => $validated['nameservers']
                ? array_map('trim', explode(',', $validated['nameservers']))
                : null,
        ]);

        $imported = $allocator->importPool(
            $pool,
            $validated['range_from'] ?? null,
            $validated['range_to'] ?? null,
        );

        AuditLog::record('ip_pool.imported', $pool, ['cidr' => $pool->cidr, 'imported' => $imported]);

        return back()->with('status', "Zaimportowano {$imported} adresów do puli {$pool->name}.");
    }

    // --- maszyny ------------------------------------------------------------

    public function servers(Request $request): View
    {
        $servers = Server::query()
            ->with(['user:id,name,email', 'package', 'hypervisor', 'ipAddresses'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($sub) => $sub
                    ->where('hostname', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $term)));
            })
            ->when($request->filled('state'), fn ($q) => $q->where('state', $request->string('state')))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('panel.admin.servers', [
            'servers' => $servers,
            'states' => ServerState::cases(),
        ]);
    }

    // --- pomocnicze ---------------------------------------------------------

    private function enrollmentCommand(string $token): string
    {
        return sprintf(
            'curl -sSL %s/enroll/%s | sudo bash',
            rtrim(config('app.url'), '/'),
            $token,
        );
    }
}
