<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Apps\AppJobApplier;
use App\Domain\Apps\AppPayload;
use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\VariableRules;
use App\Domain\Console\ConsoleSessions;
use App\Http\Controllers\Controller;
use App\Models\AppEgg;
use App\Models\AppJob;
use App\Models\AppPlan;
use App\Models\AppServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Aplikacje klienta: lista, zamówienie, konsola, uruchamianie i ustawienia. */
class AppController extends Controller
{
    public function __construct(private readonly AppProvisioner $apps) {}

    public function index(Request $request): View
    {
        $apps = AppServer::query()
            ->where('user_id', $request->user()->id)
            ->with(['egg', 'plan', 'allocations', 'hypervisor'])
            ->latest()
            ->get();

        return view('panel.apps.index', [
            'apps' => $apps,
            'ready' => $apps->filter(fn (AppServer $a) => $a->statusTone() === 'ok')->count(),
            'busy' => $apps->filter(fn (AppServer $a) => $a->statusTone() === 'warning')->count(),
        ]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (\App\Domain\Billing\Billing::enabled() && ! $request->user()->isStaff()) {
            return redirect()->route('panel.store');
        }
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

        return view('panel.apps.show', $this->page($app) + ['tab' => 'overview']);
    }

    /**
     * Hasło SFTP aplikacji: wygeneruj nowe (pokazane raz), ustaw własne albo
     * wróć do logowania hasłem panelu. Osobne hasło można dać np. współpracownikowi.
     */
    public function sftpPassword(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['generate', 'set', 'panel'])],
            'password' => ['nullable', 'required_if:mode,set', 'confirmed', \Illuminate\Validation\Rules\Password::min(10)],
        ]);

        $redirect = redirect()->route('panel.apps.show', $app);
        switch ($data['mode']) {
            case 'generate':
                $password = \Illuminate\Support\Str::password(20, symbols: false);
                $app->forceFill(['sftp_password' => $password])->save();
                $redirect->with('sftp_password', $password)->with('status', __('Nowe hasło SFTP ustawione — zapisz je, zobaczysz je tylko teraz.'));
                break;
            case 'set':
                $app->forceFill(['sftp_password' => $data['password']])->save();
                $redirect->with('status', __('Hasło SFTP zmienione.'));
                break;
            default:
                $app->forceFill(['sftp_password' => null])->save();
                $redirect->with('status', __('SFTP używa teraz hasła do panelu.'));
        }
        \App\Models\AuditLog::record('app.sftp_password', $app, ['mode' => $data['mode']], $request->user());

        return $redirect;
    }

    /** Konsola na żywo — osobna zakładka, jak konsola maszyny. */
    public function terminal(AppServer $app): View
    {
        $this->authorize('view', $app);

        return view('panel.apps.console', $this->page($app) + ['tab' => 'console']);
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

        $data['job'] = $this->jobProgress($app);
        // Wynik mógł właśnie zostać zastosowany — stan panelu po nim.
        $data['status'] = $app->status;
        $data['status_label'] = $app->statusLabel();

        return response()->json($data);
    }

    /**
     * Etap i procent instalacji (aplikacji, modpacka, loadera) z węzła — dla
     * paska postępu. Gdy węzeł skończył, a callback jeszcze nie dotarł,
     * stosujemy wynik od razu (jak przy maszynach).
     *
     * @return array<string, mixed>|null
     */
    private function jobProgress(AppServer $app): ?array
    {
        $job = $app->jobs()->whereIn('action', ['install', 'reinstall', 'modpack', 'loader'])->first();
        if ($job === null) {
            return null;
        }

        $agent = null;
        if (! $job->isFinished() && $job->agent_job_id && $app->hypervisor) {
            $agent = Cache::remember("agent-job:{$job->agent_job_id}", now()->addSeconds(2), function () use ($app, $job) {
                try {
                    return $this->apps->client($app)->job($job->agent_job_id);
                } catch (AgentException) {
                    return null;
                }
            });
            if (in_array($agent['status'] ?? null, ['done', 'failed'], true)) {
                app(AppJobApplier::class)->apply($job, $agent);
                $job->refresh();
                $app->refresh();
            }
        }

        return [
            'id' => $job->id,
            'action' => $job->action,
            'status' => $job->status,
            'finished' => $job->isFinished(),
            'error' => $job->status === AppJob::STATUS_FAILED ? $job->error : null,
            'elapsed' => (int) $job->created_at->diffInSeconds(now(), true),
            'stage' => $job->isFinished() ? null : ($agent['stage'] ?? ($job->agent_job_id ? 'queued' : 'pending')),
            'stage_progress' => $job->isFinished() ? 100 : ($agent['progress'] ?? null),
            'stage_detail' => $job->isFinished() ? null : ($agent['detail'] ?? null),
        ];
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

    /**
     * Jednorazowa sesja konsoli na żywo (WebSocket przez przekaźnik panelu).
     * Skrypt konsoli prosi o nową przy każdym (ponownym) połączeniu.
     */
    public function consoleSession(AppServer $app, ConsoleSessions $sessions): JsonResponse
    {
        $this->authorize('operate', $app);
        $app->load('hypervisor');

        if (! ConsoleSessions::enabled()) {
            return response()->json(['enabled' => false, 'message' => __('Przekaźnik konsoli nie jest skonfigurowany — konsola działa w trybie odpytywania.')], 409);
        }

        if ($app->hypervisor === null || $app->isSuspended() || $app->status === AppServer::STATUS_DELETING) {
            return response()->json(['enabled' => true, 'message' => __('Konsola jest niedostępna dla tej aplikacji.')], 409);
        }

        return response()->json(['enabled' => true, 'path' => '/console-ws/'.$sessions->openAppSession($app)]);
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
        $data = $request->validate([
            'confirm' => ['accepted'],
            'wipe' => ['nullable', 'array'],
            'wipe.*' => [Rule::in(array_keys(AppProvisioner::WIPE_OPTIONS))],
        ], ['confirm.accepted' => __('Potwierdź reinstalację.')]);

        try {
            $this->apps->reinstall($app, $request->user(), $data['wipe'] ?? []);
        } catch (\DomainException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        return redirect()->route('panel.apps.show', $app)->with('status', ($data['wipe'] ?? []) === []
            ? __('Reinstalacja rozpoczęta — skrypt eggu uruchomi się ponownie, pliki zostają.')
            : __('Reinstalacja rozpoczęta — wybrane pliki zostaną usunięte, a skrypt eggu uruchomi się ponownie.'));
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
