<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\EggImporter;
use App\Http\Controllers\Controller;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Administracja aplikacjami: wszystkie aplikacje, szablony (eggi) i plany. */
class AppAdminController extends Controller
{
    public function __construct(private readonly AppProvisioner $apps) {}

    public function index(Request $request): View
    {
        $query = AppServer::query()->with(['user', 'egg', 'plan', 'hypervisor', 'allocations'])->latest();
        if ($search = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('uuid', 'like', "{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")));
        }

        return view('panel.admin.apps.index', [
            'apps' => $query->paginate(50)->withQueryString(),
            'search' => $search,
        ]);
    }

    // --- szablony (eggi) --------------------------------------------------------------

    public function eggs(): View
    {
        return view('panel.admin.apps.eggs', [
            'eggs' => AppEgg::query()->withCount('servers')->orderBy('category')->orderBy('name')->get(),
        ]);
    }

    public function importEgg(Request $request, EggImporter $importer): RedirectResponse
    {
        $request->validate([
            'egg_file' => ['nullable', 'file', 'max:2048'],
            'egg_json' => ['nullable', 'string', 'max:2000000'],
            'category' => ['required', Rule::in(array_keys(AppEgg::CATEGORIES))],
        ]);

        $json = $request->file('egg_file')?->get() ?? $request->input('egg_json');
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data)) {
            return back()->withInput()->withErrors(['egg' => __('Wklej albo wgraj plik JSON eggu Pterodactyla.')]);
        }

        $egg = $importer->import($data, $request->input('category'));
        AuditLog::record('app_egg.imported', $egg, ['name' => $egg->name]);

        return redirect()->route('panel.admin.apps.eggs')->with('status', __('Zaimportowano szablon :name.', ['name' => $egg->name]));
    }

    public function builtinEggs(EggImporter $importer): RedirectResponse
    {
        $count = $importer->importBuiltin();

        return redirect()->route('panel.admin.apps.eggs')->with('status', __('Wgrano wbudowane szablony: :count.', ['count' => $count]));
    }

    public function toggleEgg(AppEgg $egg): RedirectResponse
    {
        $egg->update(['is_active' => ! $egg->is_active]);

        return back()->with('status', $egg->is_active
            ? __('Szablon :name jest dostępny do zamówienia.', ['name' => $egg->name])
            : __('Szablon :name został ukryty. Istniejące aplikacje działają dalej.', ['name' => $egg->name]));
    }

    public function deleteEgg(AppEgg $egg): RedirectResponse
    {
        if ($egg->servers()->exists()) {
            return back()->withErrors(['egg' => __('Z szablonu :name korzystają aplikacje — ukryj go zamiast usuwać.', ['name' => $egg->name])]);
        }
        $egg->delete();

        return back()->with('status', __('Usunięto szablon :name.', ['name' => $egg->name]));
    }

    // --- plany ----------------------------------------------------------------------

    public function plans(): View
    {
        return view('panel.admin.apps.plans', [
            'plans' => AppPlan::query()->withCount('servers')->orderBy('memory_mb')->get(),
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $plan = AppPlan::query()->create($this->planData($request));
        AuditLog::record('app_plan.created', $plan, ['name' => $plan->name]);

        return back()->with('status', __('Dodano plan :name.', ['name' => $plan->name]));
    }

    /**
     * Edycja planu. Nowe limity RAM/procesora/dysku można od razu przenieść na
     * działające aplikacje tego planu; liczba portów dotyczy nowych zamówień
     * (istniejące mają już przydzielone porty).
     */
    public function updatePlan(Request $request, AppPlan $plan): RedirectResponse
    {
        $before = $plan->only(['name', 'memory_mb', 'cpu_percent', 'disk_mb', 'ports', 'price_hint_cents']);
        $plan->update($this->planData($request, 'edit_'.$plan->id));
        AuditLog::record('app_plan.updated', $plan, ['before' => $before, 'after' => $plan->only(array_keys($before))]);

        if (! $request->boolean('apply_existing')) {
            return back()->with('status', __('Plan :name zapisany. Zmiana dotyczy nowych aplikacji.', ['name' => $plan->name]));
        }

        $updated = 0;
        $failed = [];
        foreach ($plan->servers()->get() as $app) {
            try {
                $this->apps->updateResources($app, $plan->memory_mb, $plan->cpu_percent, $plan->disk_mb, $request->user());
                $updated++;
            } catch (\DomainException|AgentException $e) {
                // Zasoby są zapisane w panelu; węzeł dostanie je przy najbliższej operacji na aplikacji.
                $failed[] = $app->name;
            }
        }

        $message = __('Plan :name zapisany, zasoby zmienione w :count aplikacjach.', ['name' => $plan->name, 'count' => $updated]);
        if ($failed !== []) {
            return back()->with('status', $message)->withErrors(['plan' => __('Nie udało się wysłać zmian na węzeł dla: :apps. Panel ma nowe limity — węzeł dostanie je przy najbliższym restarcie lub reinstalacji.', ['apps' => implode(', ', array_slice($failed, 0, 10))])]);
        }

        return back()->with('status', $message);
    }

    /** @return array<string, mixed> */
    private function planData(Request $request, ?string $bag = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'memory_mb' => ['required', 'integer', 'min:128', 'max:1048576'],
            'cpu_percent' => ['nullable', 'integer', 'min:0', 'max:12800'],
            'disk_mb' => ['required', 'integer', 'min:256', 'max:10485760'],
            'ports' => ['required', 'integer', 'min:1', 'max:20'],
            'price_hint' => ['nullable', 'numeric', 'min:0'],
        ];
        $validated = $bag ? $request->validateWithBag($bag, $rules) : $request->validate($rules);
        unset($validated['price_hint']);

        return [
            ...$validated,
            'cpu_percent' => (int) ($validated['cpu_percent'] ?? 0),
            'price_hint_cents' => $request->filled('price_hint') ? (int) round((float) $request->input('price_hint') * 100) : null,
        ];
    }

    public function togglePlan(AppPlan $plan): RedirectResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return back()->with('status', $plan->is_active ? __('Plan :name jest w sprzedaży.', ['name' => $plan->name]) : __('Plan :name wycofany ze sprzedaży.', ['name' => $plan->name]));
    }

    public function deletePlan(AppPlan $plan): RedirectResponse
    {
        if ($plan->servers()->exists()) {
            return back()->withErrors(['plan' => __('Plan :name ma aplikacje — wycofaj go zamiast usuwać.', ['name' => $plan->name])]);
        }
        $plan->delete();

        return back()->with('status', __('Usunięto plan :name.', ['name' => $plan->name]));
    }

    // --- operacje na aplikacjach klientów --------------------------------------------------

    public function suspend(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('manage', $app);

        if ($app->isSuspended()) {
            $this->apps->unsuspend($app, $request->user());

            return back()->with('status', __('Aplikacja :name została odwieszona.', ['name' => $app->name]));
        }

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;
        $this->apps->suspend($app, $reason ?: __('Zawieszona przez administratora.'), $request->user());

        return back()->with('status', __('Aplikacja :name została zawieszona i zatrzymana.', ['name' => $app->name]));
    }

    /** Zwolnienie z ochrony przed nadużyciami — tylko administrator (fałszywy alarm). */
    public function abuseExempt(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('manage', $app);
        abort_unless($request->user()->isAdmin(), 403);

        try {
            $this->apps->setAbuseExempt($app, ! $app->abuse_exempt, $request->user());
        } catch (\DomainException|AgentException $e) {
            return back()->withErrors(['abuse' => $e->getMessage()]);
        }

        return back()->with('status', $app->abuse_exempt
            ? __('Aplikacja :name jest zwolniona z ochrony przed nadużyciami.', ['name' => $app->name])
            : __('Ochrona przed nadużyciami znów obejmuje aplikację :name.', ['name' => $app->name]));
    }

    public function resources(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('manage', $app);
        $validated = $request->validate([
            'memory_mb' => ['required', 'integer', 'min:128', 'max:1048576'],
            'cpu_percent' => ['nullable', 'integer', 'min:0', 'max:12800'],
            'disk_mb' => ['required', 'integer', 'min:256', 'max:10485760'],
        ]);

        try {
            $restart = $this->apps->updateResources($app, (int) $validated['memory_mb'], (int) ($validated['cpu_percent'] ?? 0), (int) $validated['disk_mb'], $request->user());
        } catch (\DomainException|AgentException $e) {
            return back()->withErrors(['memory_mb' => $e->getMessage()]);
        }

        return back()->with('status', $restart
            ? __('Zapisano zasoby. Limity pamięci i procesora działają od razu, reszta po restarcie.')
            : __('Zapisano zasoby aplikacji.'));
    }

    public function purge(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('manage', $app);
        abort_unless($request->user()->isAdmin(), 403);
        $this->apps->destroy($app, $request->user(), panelOnly: true);

        return redirect()->route('panel.admin.apps')->with('status', __('Usunięto :name z panelu. Pliki na węźle usuń ręcznie, jeśli węzeł jeszcze istnieje.', ['name' => $app->name]));
    }
}
