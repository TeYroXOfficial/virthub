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
use App\Models\OsTemplateGroup;
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
            'groups' => $this->groupsForView(),
        ]);
    }

    public function showHypervisor(Hypervisor $hypervisor): View
    {
        $hypervisor->load('group')->loadCount('servers');

        return view('panel.admin.hypervisor', [
            'node' => $hypervisor,
            'servers' => $hypervisor->servers()->with(['user:id,email', 'ipAddresses', 'template'])->latest()->get(),
            'groups' => HypervisorGroup::query()->ordered()->get(),
            'pools' => IpPool::query()->where('hypervisor_id', $hypervisor->id)
                ->orWhere(fn ($q) => $q->whereNotNull('hypervisor_group_id')->where('hypervisor_group_id', $hypervisor->hypervisor_group_id))
                ->withCount(['addresses', 'addresses as assigned_count' => fn ($q) => $q->whereNotNull('server_id')])
                ->orderBy('version')->get(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, HypervisorGroup> */
    private function groupsForView()
    {
        return HypervisorGroup::query()
            ->with('hypervisors:id,name,hypervisor_group_id,status,last_seen_at')
            ->withCount('ipPools')
            ->ordered()
            ->get();
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
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('hypervisors', 'name')->ignore($hypervisor->id)],
            'max_servers' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'cpu_model' => ['nullable', 'string', 'max:120'],
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
            'max_servers' => $validated['max_servers'] ?? null,
            'cpu_model' => isset($validated['cpu_model']) ? trim($validated['cpu_model']) ?: null : null,
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

    public function destroyHypervisor(Request $request, Hypervisor $hypervisor, \App\Domain\Provisioning\ServerProvisioner $provisioner): RedirectResponse
    {
        // Martwy węzeł z maszynami: administrator może usunąć go razem z ich
        // wpisami w panelu (bez kontaktu z węzłem) — po wpisaniu nazwy węzła.
        if ($request->boolean('purge_servers') && $hypervisor->servers()->exists()) {
            abort_unless($request->user()->isAdmin(), 403, 'Usunąć węzeł z maszynami może tylko administrator.');

            if ($request->input('confirm_name') !== $hypervisor->name) {
                return back()->withErrors(['delete' => 'Wpisz dokładną nazwę węzła, żeby potwierdzić usunięcie razem z maszynami.']);
            }

            foreach ($hypervisor->servers()->get() as $server) {
                $provisioner->purge($server, $request->user());
            }
        }

        if ($hypervisor->servers()->exists()) {
            return back()->withErrors([
                'delete' => "Na węźle {$hypervisor->name} są maszyny. Usuń je najpierw — "
                    .'skasowanie węzła zostawiłoby je bez opieki.',
            ]);
        }

        AuditLog::record('hypervisor.deleted', $hypervisor, ['name' => $hypervisor->name]);
        $hypervisor->delete();

        return redirect()->route('panel.admin.hypervisors')->with('status', "Węzeł {$hypervisor->name} został usunięty.");
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
            ->withCount('servers')
            ->orderBy('sort_order')
            ->orderBy('virtualization')
            ->orderByDesc('version')
            ->get();

        $existingAliases = $templates
            ->filter(fn (OsTemplate $t) => $t->isContainer())
            ->pluck('image_file')
            ->all();

        return view('panel.admin.templates', [
            'templates' => $templates,
            'groups' => OsTemplateGroup::query()->ordered()->get(),
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

        // Wersja dodawana do istniejącego systemu dziedziczy jego rodzinę.
        if ($request->filled('os_template_group_id') && ! $request->filled('family')) {
            $request->merge(['family' => OsTemplateGroup::find($request->integer('os_template_group_id'))?->family]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'os_template_group_id' => ['nullable', 'integer', 'exists:os_template_groups,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'virtualization' => ['required', Rule::enum(Virtualization::class)],
            'family' => ['required', Rule::in(array_keys(OsTemplateGroup::FAMILIES))],
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
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
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

    /** Edycja wersji: nazwa, wersja, system (grupa), kolejność, minimalny dysk. */
    public function updateTemplate(Request $request, OsTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'version' => ['required', 'string', 'max:32'],
            'os_template_group_id' => ['nullable', 'integer', 'exists:os_template_groups,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'min_disk_gb' => ['required', 'integer', 'min:1'],
        ]);

        $template->update([...$validated, 'sort_order' => (int) ($validated['sort_order'] ?? 0)]);
        AuditLog::record('template.updated', $template, $validated);

        return back()->with('status', "Zapisano {$template->name}.");
    }

    public function destroyTemplate(OsTemplate $template): RedirectResponse
    {
        $count = $template->servers()->count();
        if ($count > 0) {
            return back()->withErrors([
                'template' => "Na {$template->name} stoi {$count} maszyn — usuń je albo przeinstaluj na inny system. "
                    .'Możesz też tylko wyłączyć ten szablon, żeby nie był dostępny do zamówienia.',
            ]);
        }

        AuditLog::record('template.deleted', $template, ['name' => $template->name]);
        $template->delete();

        return back()->with('status', "Usunięto szablon {$template->name}. Plik obrazu na węzłach usuń ręcznie, jeśli nie jest potrzebny.");
    }

    /**
     * Postęp pobierań szablonów i obrazów ISO na żywo. Pyta węzły o trwające
     * zadania (cache 2 s), więc pasek nie czeka na kolejny obieg kolejki.
     */
    public function downloadsStatus(): \Illuminate\Http\JsonResponse
    {
        $rows = [];

        $track = function ($download, string $kind, callable $apply) use (&$rows) {
            $state = null;
            if ($download->agent_job_id && $download->hypervisor) {
                $state = \Illuminate\Support\Facades\Cache::remember(
                    "agent-job:{$download->agent_job_id}",
                    now()->addSeconds(2),
                    function () use ($download) {
                        try {
                            return (new \App\Domain\Agent\AgentClient($download->hypervisor))->job($download->agent_job_id);
                        } catch (AgentException) {
                            return null;
                        }
                    },
                );
                if ($state !== null) {
                    $apply($download, $state);
                    $download->refresh();
                }
            }

            $rows[] = [
                'kind' => $kind,
                'id' => $download->id,
                'status' => $download->status,
                'progress' => $download->progress,
                'detail' => $download->progress_detail,
                'finished' => ! $download->isInProgress(),
            ];
        };

        $inProgress = fn ($q) => $q->whereIn('status', ['queued', 'downloading']);

        \App\Models\TemplateDownload::query()->with('hypervisor')->where($inProgress)->get()
            ->each(fn ($d) => $track($d, 'template', [\App\Jobs\PrefetchTemplateJob::class, 'apply']));
        \App\Models\IsoDownload::query()->with(['hypervisor', 'iso'])->where($inProgress)->get()
            ->each(fn ($d) => $track($d, 'iso', [\App\Jobs\DownloadIsoJob::class, 'apply']));

        return response()->json(['data' => $rows]);
    }

    // --- systemy (grupy szablonów) -------------------------------------------

    public function storeTemplateGroup(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->templateGroupRules());
        $group = OsTemplateGroup::create([...$validated, 'sort_order' => (int) ($validated['sort_order'] ?? 0), 'is_active' => true]);
        AuditLog::record('template_group.created', $group, ['name' => $group->name]);

        return back()->with('status', "Dodano system {$group->name}. Dodaj do niego wersje.");
    }

    public function updateTemplateGroup(Request $request, OsTemplateGroup $group): RedirectResponse
    {
        $validated = $request->validate($this->templateGroupRules($group));
        $group->update([...$validated, 'sort_order' => (int) ($validated['sort_order'] ?? 0)]);
        AuditLog::record('template_group.updated', $group, $validated);

        return back()->with('status', "Zapisano system {$group->name}.");
    }

    public function toggleTemplateGroup(OsTemplateGroup $group): RedirectResponse
    {
        $group->update(['is_active' => ! $group->is_active]);

        return back()->with('status', $group->is_active
            ? "System {$group->name} jest znowu dostępny."
            : "System {$group->name} został ukryty — żadna jego wersja nie jest dostępna do zamówienia ani reinstalacji.");
    }

    public function destroyTemplateGroup(OsTemplateGroup $group): RedirectResponse
    {
        if ($group->templates()->exists()) {
            return back()->withErrors(['group' => "System {$group->name} ma wersje — usuń je albo przenieś do innego systemu."]);
        }

        AuditLog::record('template_group.deleted', $group, ['name' => $group->name]);
        $group->delete();

        return back()->with('status', "Usunięto system {$group->name}.");
    }

    /** @return array<string, list<mixed>> */
    private function templateGroupRules(?OsTemplateGroup $group = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('os_template_groups', 'name')->ignore($group?->id)],
            'family' => ['required', Rule::in(array_keys(OsTemplateGroup::FAMILIES))],
            'description' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
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
            'groups' => $this->groupsForView(),
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
            ...self::groupSettingsRules(),
        ]);

        $group = $groups->create(
            $validated['name'],
            $validated['description'] ?? null,
            $validated['hypervisor_ids'] ?? [],
            $this->groupSettings($request, $validated),
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
            ...self::groupSettingsRules(),
        ]);

        // Formularz wysyła komplet zaznaczonych węzłów — brak pola znaczy
        // „żadnego węzła", a nie „bez zmian".
        $groups->update(
            $group,
            $validated['name'],
            $validated['description'] ?? null,
            $validated['hypervisor_ids'] ?? [],
            $this->groupSettings($request, $validated),
        );

        return back()->with('status', "Zapisano grupę {$group->name}.");
    }

    /** @return array<string, list<string>> */
    private static function groupSettingsRules(): array
    {
        return [
            'location' => ['nullable', 'string', 'max:100'],
            'is_public' => ['sometimes', 'boolean'],
            'accepts_new_servers' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function groupSettings(Request $request, array $validated): array
    {
        return [
            'location' => $validated['location'] ?? null,
            'is_public' => $request->boolean('is_public'),
            // Brak pola w starszym formularzu = przyjmuje (dotychczasowe zachowanie).
            'accepts_new_servers' => $request->has('accepts_new_servers') ? $request->boolean('accepts_new_servers') : true,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
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
            ->with(['user:id,name,email', 'package', 'hypervisor', 'ipAddresses', 'template'])
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

    /** Usuwanie wielu maszyn naraz — np. sprzątanie starych, nieudanych wpisów. */
    public function bulkServers(Request $request, \App\Domain\Provisioning\ServerProvisioner $provisioner): RedirectResponse
    {
        // Przycisk przy pojedynczej maszynie wysyła „akcja:id" zamiast zaznaczeń.
        if (preg_match('/^(delete|purge):(\d+)$/', (string) $request->input('row'), $m)) {
            $request->merge(['action' => $m[1], 'ids' => [(int) $m[2]]]);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['delete', 'purge'])],
        ], ['ids.required' => 'Zaznacz co najmniej jedną maszynę.']);

        $done = [];
        $skipped = [];

        foreach (Server::query()->whereIn('id', $validated['ids'])->get() as $server) {
            $ability = $validated['action'] === 'purge' ? 'purge' : 'destroy';

            if ($request->user()->cannot($ability, $server)
                || ($validated['action'] === 'delete' && $server->state === ServerState::Deleting)) {
                $skipped[] = $server->hostname;

                continue;
            }

            $validated['action'] === 'purge'
                ? $provisioner->purge($server, $request->user())
                : $provisioner->destroy($server, $request->user());
            $done[] = $server->hostname;
        }

        $message = ($validated['action'] === 'purge' ? 'Usunięto z panelu: ' : 'Zlecono usunięcie: ')
            .($done === [] ? 'nic' : implode(', ', $done)).'.';
        if ($skipped !== []) {
            $message .= ' Pominięto (brak uprawnień albo już usuwane): '.implode(', ', $skipped).'.';
        }

        return back()->with('status', $message);
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
