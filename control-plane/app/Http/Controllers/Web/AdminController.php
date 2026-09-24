<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Network\HypervisorGroupManager;
use App\Domain\Network\IpPoolManager;
use App\Domain\Provisioning\HypervisorEnrollment;
use App\Domain\Provisioning\TemplateDistributor;
use App\Enums\ServerState;
use App\Enums\Virtualization;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
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
            'hypervisors' => Hypervisor::query()->with('group')->withCount('servers')->orderBy('name')->get(),
            'groups' => HypervisorGroup::query()->orderBy('name')->get(),
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

    public function updateHypervisor(Request $request, Hypervisor $hypervisor, HypervisorGroupManager $groups): RedirectResponse
    {
        $validated = $request->validate([
            'cpu_cores_total' => ['required', 'integer', 'min:1'],
            'ram_mb_total' => ['required', 'integer', 'min:1024'],
            'disk_gb_total' => ['required', 'integer', 'min:10'],
            'bridge' => ['required', 'string', 'max:32'],
            'hypervisor_group_id' => ['nullable', 'integer', 'exists:hypervisor_groups,id'],
            'accepts_new_servers' => ['boolean'],
            'status' => ['required', Rule::in([
                Hypervisor::STATUS_ONLINE,
                Hypervisor::STATUS_OFFLINE,
                Hypervisor::STATUS_MAINTENANCE,
            ])],
        ]);

        if ($request->has('hypervisor_group_id')) {
            $groupId = $validated['hypervisor_group_id'] ?? null;
            $groups->assign($hypervisor, $groupId ? HypervisorGroup::find($groupId) : null);
        }
        unset($validated['hypervisor_group_id']);

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
            'ipv6_count' => ['nullable', 'integer', 'min:0', 'max:16'],
            'network_type' => ['nullable', Rule::in(IpPool::TYPES)],
            'price_hint' => ['nullable', 'numeric', 'min:0'],
        ]);

        $package = VpsPackage::create([
            ...$validated,
            'slug' => Str::slug($validated['name']),
            'ipv6_count' => $validated['ipv6_count'] ?? 0,
            'network_type' => $validated['network_type'] ?? IpPool::TYPE_PUBLIC,
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
        $templates = OsTemplate::query()
            ->with('downloads.hypervisor')
            ->orderBy('virtualization')
            ->orderBy('family')
            ->orderByDesc('version')
            ->get();

        $existingAliases = $templates
            ->filter(fn (OsTemplate $t) => $t->isContainer())
            ->pluck('image_file')
            ->all();

        return view('panel.admin.templates', [
            'templates' => $templates,
            'catalog' => collect(config('virthub.lxc_catalog'))
                ->map(fn (array $entry, string $key) => [
                    ...$entry,
                    'key' => $key,
                    'added' => in_array($entry['alias'], $existingAliases, true),
                ]),
            'containerNodes' => Hypervisor::query()
                ->where('virtualization', Virtualization::Lxc->value)
                ->whereNotNull('enrolled_at')
                ->count(),
        ]);
    }

    public function storeTemplate(Request $request, TemplateDistributor $distributor): RedirectResponse
    {
        // Bez podanego rodzaju to szablon KVM — tak działał formularz, zanim
        // pojawiły się kontenery.
        $request->mergeIfMissing(['virtualization' => Virtualization::Kvm->value]);
        $isContainer = $request->input('virtualization') === Virtualization::Lxc->value;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'virtualization' => ['required', Rule::enum(Virtualization::class)],
            'family' => ['required', Rule::in(['ubuntu', 'debian', 'almalinux', 'rocky', 'fedora', 'windows'])],
            'version' => ['required', 'string', 'max:32'],
            'image_file' => $isContainer
                // Alias obrazu z serwera obrazów. Bez prefiksu serwera
                // („inny:debian/12") — węzeł pobiera wyłącznie z zaufanego źródła.
                ? ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]*(\/[a-z0-9._-]+){0,3}$/']
                // Sama nazwa pliku — układ katalogów należy do agenta, a wartość
                // ze ścieżką pozwoliłaby sięgnąć poza katalog szablonów.
                : ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'min_disk_gb' => ['required', 'integer', 'min:1'],
        ], [
            'image_file.regex' => $isContainer
                ? 'Podaj alias obrazu kontenera, np. debian/12/cloud (małe litery, bez prefiksu serwera).'
                : 'Podaj samą nazwę pliku obrazu, bez ścieżki (np. ubuntu-24.04.qcow2).',
        ]);

        if ($isContainer && $validated['family'] === 'windows') {
            return back()->withInput()->withErrors([
                'family' => 'Windows nie działa w kontenerze — kontener dzieli jądro Linuksa z hostem.',
            ]);
        }

        $template = OsTemplate::create([
            ...$validated,
            'cloud_init_support' => $validated['family'] !== 'windows',
            'is_active' => true,
        ]);

        AuditLog::record('template.created', $template, ['name' => $template->name]);

        if ($template->isContainer()) {
            $queued = $distributor->distribute($template);

            return back()->with('status', "Szablon {$template->name} został dodany. "
                ."Pobieranie zlecone na {$queued} ".($queued === 1 ? 'węźle' : 'węzłach').' kontenerów.');
        }

        return back()->with('status', "Szablon {$template->name} został dodany. "
            ."Upewnij się, że plik {$template->image_file} leży w katalogu szablonów na każdym węźle KVM.");
    }

    /** Dodanie szablonu kontenera z katalogu jednym kliknięciem. */
    public function addCatalogTemplate(string $key, TemplateDistributor $distributor): RedirectResponse
    {
        $entry = config("virthub.lxc_catalog.{$key}");
        abort_if($entry === null, 404);

        $template = OsTemplate::query()->firstOrCreate(
            ['image_file' => $entry['alias'], 'virtualization' => Virtualization::Lxc->value],
            [
                'name' => $entry['name'],
                'family' => $entry['family'],
                'version' => $entry['version'],
                'min_disk_gb' => $entry['min_disk_gb'] ?? 4,
                'cloud_init_support' => true,
                'is_active' => true,
            ],
        );

        if (! $template->is_active) {
            $template->update(['is_active' => true]);
        }

        AuditLog::record('template.created', $template, ['name' => $template->name, 'catalog' => $key]);
        $queued = $distributor->distribute($template);

        return back()->with('status', "Dodano {$template->name}. "
            .($queued > 0
                ? "Pobieranie zlecone na {$queued} ".($queued === 1 ? 'węźle' : 'węzłach').'.'
                : 'Nie ma jeszcze węzła kontenerów — szablon pobierze się, gdy taki dołączy.'));
    }

    public function retryTemplate(OsTemplate $template, TemplateDistributor $distributor): RedirectResponse
    {
        $queued = $distributor->retryFailed($template);

        return back()->with('status', $queued > 0
            ? "Ponowiono pobieranie {$template->name} na {$queued} ".($queued === 1 ? 'węźle' : 'węzłach').'.'
            : "Szablon {$template->name} nie ma nieudanych pobrań do ponowienia.");
    }

    public function toggleTemplate(OsTemplate $template, TemplateDistributor $distributor): RedirectResponse
    {
        $template->update(['is_active' => ! $template->is_active]);

        // Włączony z powrotem szablon kontenera trafia na węzły, które mogły
        // dołączyć, kiedy był wyłączony.
        if ($template->is_active) {
            $distributor->distribute($template);
        }

        return back()->with('status', $template->is_active
            ? "Szablon {$template->name} jest znowu dostępny."
            : "Szablon {$template->name} został wyłączony.");
    }

    // --- pule adresów -------------------------------------------------------

    public function ipPools(): View
    {
        return view('panel.admin.ip-pools', [
            'pools' => IpPool::query()->with(['hypervisor', 'group'])->withCount([
                'addresses',
                'addresses as assigned_count' => fn ($q) => $q->whereNotNull('server_id'),
                'addresses as reserved_count' => fn ($q) => $q->where('is_reserved', true),
            ])->orderBy('version')->orderBy('type')->orderBy('name')->get(),
            'hypervisors' => Hypervisor::query()->with('group')->orderBy('name')->get(),
            'groups' => HypervisorGroup::query()->with('hypervisors:id,name,hypervisor_group_id')
                ->withCount('ipPools')->orderBy('name')->get(),
        ]);
    }

    public function storeIpPool(Request $request, IpPoolManager $pools): RedirectResponse
    {
        $validated = $request->validate(IpPoolManager::rules());

        ['pool' => $pool, 'imported' => $imported] = $pools->create($validated);

        return back()->with('status', $pool->version === 4
            ? "Zaimportowano {$imported} adresów do puli {$pool->name}."
            : "Dodano pulę IPv6 {$pool->name}. Adresy będą przydzielane kolejno przy zamówieniach.");
    }

    public function destroyIpPool(IpPool $pool, IpPoolManager $pools): RedirectResponse
    {
        $pools->delete($pool);

        return back()->with('status', "Pula {$pool->name} została usunięta.");
    }

    // --- grupy węzłów -------------------------------------------------------

    public function storeHypervisorGroup(Request $request, HypervisorGroupManager $groups): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:hypervisor_groups,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'hypervisor_ids' => ['array'],
            'hypervisor_ids.*' => ['integer', 'exists:hypervisors,id'],
        ]);

        $group = $groups->create(
            $validated['name'],
            $validated['description'] ?? null,
            $validated['hypervisor_ids'] ?? [],
        );

        return back()->with('status', "Utworzono grupę {$group->name}.");
    }

    public function updateHypervisorGroup(Request $request, HypervisorGroup $group, HypervisorGroupManager $groups): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('hypervisor_groups', 'name')->ignore($group->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'hypervisor_ids' => ['array'],
            'hypervisor_ids.*' => ['integer', 'exists:hypervisors,id'],
        ]);

        // Formularz wysyła komplet zaznaczonych węzłów — brak pola znaczy
        // „żadnego węzła", a nie „bez zmian".
        $groups->update($group, $validated['name'], $validated['description'] ?? null, $validated['hypervisor_ids'] ?? []);

        return back()->with('status', "Zapisano grupę {$group->name}.");
    }

    public function destroyHypervisorGroup(HypervisorGroup $group, HypervisorGroupManager $groups): RedirectResponse
    {
        $groups->delete($group);

        return back()->with('status', 'Grupa została usunięta. Węzły działają dalej bez grupy.');
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
