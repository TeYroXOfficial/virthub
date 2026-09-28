<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Apps\AppPayload;
use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\VariableRules;
use App\Http\Controllers\Controller;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\AppServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Aplikacje klienta: lista, zamówienie, konsola, uruchamianie i ustawienia. */
class AppController extends Controller
{
    public function __construct(private readonly AppProvisioner $apps) {}

    public function index(Request $request): View
    {
        return view('panel.apps.index', [
            'apps' => AppServer::query()
                ->where('user_id', $request->user()->id)
                ->with(['egg', 'plan', 'allocations', 'hypervisor'])
                ->latest()
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', AppServer::class);

        return view('panel.apps.create', [
            'eggs' => AppEgg::query()->active()->orderBy('category')->orderBy('name')->get()->groupBy('category'),
            'plans' => AppPlan::query()->active()->orderBy('memory_mb')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', AppServer::class);

        $validated = $request->validate([
            'egg' => ['required', 'integer', Rule::exists('app_eggs', 'id')->where('is_active', true)],
            'plan' => ['required', 'integer', Rule::exists('app_plans', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:60'],
            'image' => ['nullable', 'string', 'max:255'],
        ]);

        $egg = AppEgg::query()->findOrFail($validated['egg']);
        $image = $validated['image'] ?? null;
        if ($image !== null && ! in_array($image, $egg->images(), true)) {
            $image = null;
        }

        try {
            $app = $this->apps->order(
                $request->user(), $egg, AppPlan::query()->findOrFail($validated['plan']),
                $validated['name'], $image,
            );
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['plan' => $e->getMessage()]);
        }

        return redirect()->route('panel.apps.show', $app)
            ->with('status', __('Aplikacja jest instalowana. Konsola pokaże postęp instalacji.'));
    }

    // --- strona aplikacji ------------------------------------------------------

    public function show(AppServer $app): View
    {
        $this->authorize('view', $app);

        return view('panel.apps.show', $this->page($app) + ['tab' => 'console']);
    }

    public function startup(AppServer $app): View
    {
        $this->authorize('view', $app);

        return view('panel.apps.startup', $this->page($app) + [
            'tab' => 'startup',
            'preview' => AppPayload::startupPreview($app),
        ]);
    }

    public function settings(AppServer $app): View
    {
        $this->authorize('view', $app);

        return view('panel.apps.settings', $this->page($app) + ['tab' => 'settings']);
    }

    private function page(AppServer $app): array
    {
        $app->load(['egg', 'plan', 'allocations', 'hypervisor', 'user']);

        return ['app' => $app, 'lastJob' => $app->jobs()->first()];
    }

    // --- konsola i zasilanie (JSON dla skryptu konsoli) ---------------------------

    public function status(AppServer $app): JsonResponse
    {
        $this->authorize('view', $app);
        $app->load('hypervisor');

        $data = [
            'status' => $app->status,
            'status_label' => $app->statusLabel(),
            'status_message' => $app->status_message,
            'suspended' => $app->isSuspended(),
            'state' => $app->isInstalling() ? 'installing' : ($app->isReady() ? 'unknown' : 'offline'),
        ];

        if ($app->acceptsCommands()) {
            try {
                // Stan z węzła nadpisuje przybliżenie z panelu.
                $data = array_merge($data, $this->apps->client($app)->appStatus($app->uuid));
            } catch (AgentException $e) {
                $data['state'] = 'unreachable';
                $data['error'] = $e->getMessage();
            }
        }

        return response()->json($data);
    }

    public function logs(Request $request, AppServer $app): JsonResponse
    {
        $this->authorize('view', $app);
        $since = $request->input('since');

        if ($app->hypervisor === null || $app->isSuspended()) {
            return response()->json(['lines' => [], 'cursor' => null, 'source' => 'none']);
        }

        try {
            return response()->json($this->apps->client($app)->appLogs(
                $app->uuid, is_numeric($since) ? (float) $since : null, min(500, max(1, (int) $request->input('tail', 200))),
            ));
        } catch (AgentException $e) {
            return response()->json(['lines' => [], 'cursor' => $since, 'error' => $e->getMessage()], 200);
        }
    }

    public function power(Request $request, AppServer $app): JsonResponse
    {
        $this->authorize('operate', $app);
        $action = $request->validate(['action' => ['required', Rule::in(['start', 'stop', 'restart', 'kill'])]])['action'];

        try {
            return response()->json($this->apps->power($app, $action, $request->user()));
        } catch (\DomainException|AgentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function command(Request $request, AppServer $app): JsonResponse
    {
        $this->authorize('operate', $app);
        $command = $request->validate(['command' => ['required', 'string', 'max:2000', 'regex:/^[^\r\n\x00]+$/']])['command'];

        try {
            $this->apps->command($app, $command);
        } catch (\DomainException|AgentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['sent' => true]);
    }

    // --- uruchamianie i ustawienia ---------------------------------------------------

    public function updateStartup(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $app->load('egg');

        $request->validate(['image' => ['nullable', 'string', Rule::in(array_values($app->egg->images()))]]);
        $asStaff = $request->user()->can('manage', $app);
        $variables = VariableRules::validate($app->egg, (array) $request->input('variables', []), $asStaff);

        try {
            $restart = $this->apps->updateStartup($app, $request->input('image'), $variables, $request->user());
        } catch (\DomainException|AgentException $e) {
            return back()->withErrors(['variables' => $e->getMessage()]);
        }

        return redirect()->route('panel.apps.startup', $app)->with('status', $restart
            ? __('Zapisano. Zmiany zadziałają po restarcie aplikacji.')
            : __('Zapisano ustawienia uruchamiania.'));
    }

    public function rename(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $app->update($request->validate(['name' => ['required', 'string', 'max:60']]));

        return redirect()->route('panel.apps.settings', $app)->with('status', __('Zmieniono nazwę aplikacji.'));
    }

    public function reinstall(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('Potwierdź reinstalację.')]);

        try {
            $this->apps->reinstall($app, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        return redirect()->route('panel.apps.show', $app)->with('status', __('Reinstalacja rozpoczęta — skrypt eggu uruchomi się ponownie, pliki zostają.'));
    }

    public function destroy(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('destroy', $app);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('Potwierdź usunięcie aplikacji.')]);

        try {
            $this->apps->destroy($app, $request->user());
        } catch (\DomainException|AgentException $e) {
            throw ValidationException::withMessages(['confirm' => $e->getMessage()]);
        }

        return redirect()->route($request->user()->id === $app->user_id ? 'panel.apps.index' : 'panel.admin.apps')
            ->with('status', __('Aplikacja :name została usunięta razem z plikami.', ['name' => $app->name]));
    }
}
