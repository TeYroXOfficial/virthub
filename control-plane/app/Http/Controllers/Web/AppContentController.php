<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Apps\Content\ContentException;
use App\Domain\Apps\Content\ContentManager;
use App\Domain\Apps\Content\Loaders;
use App\Domain\Apps\Content\ServerProfile;
use App\Http\Controllers\Controller;
use App\Models\AppAddon;
use App\Models\AppJob;
use App\Models\AppServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Zakładki Modpacki oraz Pluginy/Mody aplikacji Minecraft — instalacja jednym kliknięciem. */
class AppContentController extends Controller
{
    public function __construct(private readonly ContentManager $content) {}

    // --- modpacki ------------------------------------------------------------------------------

    public function modpacks(Request $request, AppServer $app): View
    {
        $this->authorize('view', $app);
        abort_unless($app->loadMissing('egg')->isMinecraft(), 404);

        $sources = $this->content->sources('modpack');
        $source = array_key_exists($request->query('source'), $sources) ? $request->query('source') : array_key_first($sources);
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $page = max(1, min(50, (int) $request->query('page', 1)));

        [$results, $error] = $this->safely(fn () => $this->content->search('modpack', $source, $query, $page, new ServerProfile($app)));

        return view('panel.apps.modpacks', $this->page($app) + [
            'tab' => 'modpacks',
            'sources' => $sources, 'source' => $source, 'query' => $query, 'page' => $page,
            'results' => $results, 'error' => $error,
            'profile' => new ServerProfile($app),
            'mcVersions' => $this->mcVersions(),
        ]);
    }

    public function modpack(AppServer $app, string $source, string $project): View
    {
        $this->authorize('view', $app);
        abort_unless($app->loadMissing('egg')->isMinecraft(), 404);

        [$data, $error] = $this->safely(fn () => [
            'project' => $this->content->project('modpack', $source, $project),
            'versions' => array_slice($this->content->versions('modpack', $source, $project, new ServerProfile($app)), 0, 40),
        ]);

        return view('panel.apps.modpack', $this->page($app) + [
            'tab' => 'modpacks', 'source' => $source, 'sourceName' => ContentManager::SOURCES['modpack'][$source] ?? $source,
            'project' => $data['project'] ?? null, 'versions' => $data['versions'] ?? [], 'error' => $error,
        ]);
    }

    public function installModpack(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(ContentManager::SOURCES['modpack']))],
            'project' => ['required', 'string', 'max:100'],
            'version' => ['required', 'string', 'max:100'],
            'wipe_world' => ['nullable', 'boolean'],
            'wipe_plugins' => ['nullable', 'boolean'],
            'eula' => ['accepted'],
        ], ['eula.accepted' => __('Zaakceptuj EULA Minecrafta, żeby zainstalować serwer.')]);

        return $this->queued(fn () => $this->content->queueModpack(
            $app, $data['source'], $data['project'], $data['version'], (bool) ($data['wipe_world'] ?? false), $request->user(),
            (bool) ($data['wipe_plugins'] ?? false),
        ), $app, __('Instalacja modpacka rozpoczęta — postęp widać w konsoli.'));
    }

    public function installLoader(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $data = $request->validate([
            'loader' => ['required', Rule::in(Loaders::TYPES)],
            'mc' => ['required', 'string', 'regex:/^\d+\.\d+(\.\d+)?$/'],
            'loader_version' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._+-]+$/'],
            'wipe_world' => ['nullable', 'boolean'],
            'wipe_plugins' => ['nullable', 'boolean'],
            'eula' => ['accepted'],
        ], ['eula.accepted' => __('Zaakceptuj EULA Minecrafta, żeby zainstalować serwer.')]);

        return $this->queued(fn () => $this->content->queueLoader(
            $app, $data['loader'], $data['mc'], $data['loader_version'] ?? null, (bool) ($data['wipe_world'] ?? false), $request->user(),
            (bool) ($data['wipe_plugins'] ?? false),
        ), $app, __('Instalacja serwera rozpoczęta — postęp widać w konsoli.'));
    }

    // --- pluginy i mody ------------------------------------------------------------------------------

    public function addons(Request $request, AppServer $app): View
    {
        $this->authorize('view', $app);
        abort_unless($app->loadMissing('egg')->isMinecraft(), 404);
        $profile = new ServerProfile($app->loadMissing('hypervisor'));
        $kind = $profile->addonKind();

        $sources = $kind ? $this->content->sources($kind) : [];
        $source = array_key_exists($request->query('source'), $sources) ? $request->query('source') : array_key_first($sources);
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $page = max(1, min(50, (int) $request->query('page', 1)));
        $mc = $kind ? $profile->gameVersion() : null;

        [$results, $error] = $kind && $mc
            ? $this->safely(fn () => $this->content->search($kind, $source, $query, $page, $profile))
            : [null, null];
        $updates = $kind && $request->boolean('updates')
            ? Cache::remember("app:{$app->id}:addon-updates:".$app->addons()->max('updated_at'), 600, fn () => $this->content->updates($app))
            : null;

        return view('panel.apps.addons', $this->page($app) + [
            'tab' => 'addons', 'kind' => $kind, 'profile' => $profile, 'mc' => $mc,
            'sources' => $sources, 'source' => $source, 'query' => $query, 'page' => $page,
            'results' => $results, 'error' => $error,
            'installed' => $app->addons()->get(), 'updates' => $updates,
        ]);
    }

    public function addon(AppServer $app, string $source, string $project): View
    {
        $this->authorize('view', $app);
        $profile = new ServerProfile($app->loadMissing(['egg', 'hypervisor']));
        $kind = $profile->addonKind();
        abort_if($kind === null, 404);

        [$data, $error] = $this->safely(fn () => [
            'project' => $this->content->project($kind, $source, $project),
            'versions' => array_slice($this->content->versions($kind, $source, $project, $profile), 0, 30),
        ]);

        return view('panel.apps.addon', $this->page($app) + [
            'tab' => 'addons', 'kind' => $kind, 'profile' => $profile, 'source' => $source,
            'sourceName' => ContentManager::SOURCES[$kind][$source] ?? $source,
            'project' => $data['project'] ?? null, 'versions' => $data['versions'] ?? [], 'error' => $error,
            'installed' => $app->addons()->where('source', $source)->where('project_id', $data['project']['id'] ?? $project)->first(),
        ]);
    }

    public function installAddon(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $data = $request->validate([
            'source' => ['required', 'string', 'max:20'],
            'project' => ['required', 'string', 'max:100'],
            'version' => ['required', 'string', 'max:100'],
        ]);

        return $this->queued(fn () => $this->content->queueAddon($app, $data['source'], $data['project'], $data['version'], $request->user()),
            $app, __('Instaluję — plik pojawi się za chwilę. Nowe pluginy/mody działają po restarcie serwera.'), 'panel.apps.addons');
    }

    public function removeAddon(Request $request, AppServer $app, AppAddon $addon): RedirectResponse
    {
        $this->authorize('operate', $app);
        abort_unless($addon->app_server_id === $app->id, 404);

        try {
            $this->content->removeAddon($app, $addon, $request->user());
        } catch (AgentException $e) {
            return back()->withErrors(['content' => $e->getMessage()]);
        }

        return back()->with('status', __('Usunięto :name. Zmiana zadziała po restarcie serwera.', ['name' => $addon->name]));
    }

    // --- pomocnicze ------------------------------------------------------------------------------------

    private function page(AppServer $app): array
    {
        $app->load(['egg', 'plan', 'allocations', 'hypervisor', 'user']);
        $job = $app->jobs()->whereIn('action', ContentManager::ACTIONS)->first();

        return ['app' => $app, 'contentJob' => $job, 'lastJob' => $app->jobs()->first()];
    }

    private function queued(callable $queue, AppServer $app, string $message, string $route = 'panel.apps.show'): RedirectResponse
    {
        try {
            /** @var AppJob $job */
            $job = $queue();
        } catch (ContentException $e) {
            return back()->withInput()->withErrors(['content' => $e->getMessage()]);
        }

        return redirect()->route($route, $app)->with('status', $message);
    }

    /** @return array{0: mixed, 1: ?string} */
    private function safely(callable $fn): array
    {
        try {
            return [$fn(), null];
        } catch (ContentException $e) {
            return [null, $e->getMessage()];
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return [null, __('Serwis nie odpowiada — spróbuj za chwilę.')];
        }
    }

    /** @return list<string> wydania Minecrafta, najnowsze pierwsze */
    private function mcVersions(): array
    {
        try {
            return array_values(array_map(fn ($v) => $v['id'], array_filter(Loaders::mojangVersions(), fn ($v) => $v['type'] === 'release')));
        } catch (\Throwable) {
            return ['1.21.1', '1.20.1', '1.19.2', '1.18.2', '1.16.5', '1.12.2'];
        }
    }
}
