<?php

namespace App\Http\Controllers\Web;

use App\Domain\Apps\Databases\DatabaseBrowser;
use App\Domain\Apps\Databases\DatabaseException;
use App\Domain\Apps\Databases\DatabaseManager;
use App\Http\Controllers\Controller;
use App\Models\AppDatabase;
use App\Models\AppServer;
use App\Models\AuditLog;
use App\Models\DatabaseHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Zakładka „Bazy danych” aplikacji i przeglądarka bazy. */
class AppDatabaseController extends Controller
{
    public function __construct(private readonly DatabaseManager $databases) {}

    public function index(Request $request, AppServer $app): View
    {
        $this->authorize('view', $app);
        $app->load(['egg', 'plan', 'allocations', 'hypervisor', 'user', 'databases.host']);

        return view('panel.apps.databases', [
            'app' => $app,
            'tab' => 'databases',
            'limit' => $this->databases->limit($app),
            'sizes' => $app->databases->mapWithKeys(fn (AppDatabase $db) => [$db->id => $this->databases->size($db)]),
            'hostAvailable' => $this->databases->pickHost($app) !== null,
            'hosts' => $request->user()->can('manage', $app) ? DatabaseHost::query()->where('is_active', true)->orderBy('name')->get() : collect(),
        ]);
    }

    public function store(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $staff = $request->user()->can('manage', $app);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'remote' => ['nullable', 'string', 'max:64', 'regex:'.DatabaseManager::REMOTE_PATTERN],
            'host' => ['nullable', 'integer', Rule::exists('database_hosts', 'id')],
        ], ['name.regex' => __('Nazwa bazy: małe litery, cyfry i podkreślenie, do 40 znaków.')]);

        try {
            $db = $this->databases->create($app, $data['name'], $data['remote'] ?? '%', $request->user(), $staff,
                $staff && ! empty($data['host']) ? DatabaseHost::query()->find($data['host']) : null);
        } catch (DatabaseException $e) {
            return back()->withInput()->withErrors(['database' => $e->getMessage()]);
        }

        return back()->with('status', __('Baza :name utworzona.', ['name' => $db->database]));
    }

    public function password(Request $request, AppServer $app, AppDatabase $database): RedirectResponse
    {
        $this->authorizeDatabase($app, $database, 'operate');
        try {
            $this->databases->rotatePassword($database, $request->user());
        } catch (DatabaseException $e) {
            return back()->withErrors(['database' => $e->getMessage()]);
        }

        return back()->with('status', __('Nowe hasło do bazy :name ustawione — zaktualizuj konfigurację aplikacji.', ['name' => $database->database]));
    }

    public function destroy(Request $request, AppServer $app, AppDatabase $database): RedirectResponse
    {
        $this->authorizeDatabase($app, $database, 'operate');
        $request->validate(['confirm' => ['required', 'in:'.$database->database]], ['confirm.in' => __('Wpisz nazwę bazy, żeby potwierdzić usunięcie.')]);
        try {
            $this->databases->delete($database, $request->user());
        } catch (DatabaseException $e) {
            return back()->withErrors(['database' => $e->getMessage()]);
        }

        return redirect()->route('panel.apps.databases', $app)->with('status', __('Baza :name usunięta razem z danymi.', ['name' => $database->database]));
    }

    /** Personel: limit baz tej aplikacji (puste = z planu). */
    public function limit(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('manage', $app);
        $data = $request->validate(['database_limit' => ['nullable', 'integer', 'between:0,100']]);
        $app->forceFill(['database_limit' => $data['database_limit'] ?? null])->save();
        AuditLog::record('app.database_limit', $app, ['limit' => $app->database_limit], $request->user());

        return back()->with('status', __('Limit baz zapisany.'));
    }

    // --- przeglądarka -----------------------------------------------------------------

    public function browse(Request $request, AppServer $app, AppDatabase $database): View|RedirectResponse
    {
        $this->authorizeDatabase($app, $database, 'operate');
        try {
            $browser = new DatabaseBrowser($database);
            $tables = $browser->tables();
        } catch (DatabaseException $e) {
            return redirect()->route('panel.apps.databases', $app)->withErrors(['database' => $e->getMessage()]);
        }

        return view('panel.apps.database-browse', $this->page($app, $database) + [
            'tables' => $tables,
            'sql' => (string) $request->session()->get('sql', ''),
            'result' => $request->session()->get('result'),
        ]);
    }

    public function table(Request $request, AppServer $app, AppDatabase $database, string $table): View|RedirectResponse
    {
        $this->authorizeDatabase($app, $database, 'operate');
        $page = max(1, $request->integer('page', 1));
        try {
            $browser = new DatabaseBrowser($database);
            $tables = $browser->tables();
            $columns = $browser->columns($table);
            $data = $browser->rows($table, $page, 50, $request->query('sort'), $request->query('dir') === 'desc' ? 'desc' : 'asc');
        } catch (DatabaseException $e) {
            return redirect()->route('panel.apps.databases.browse', [$app, $database])->withErrors(['database' => $e->getMessage()]);
        }

        return view('panel.apps.database-table', $this->page($app, $database) + [
            'tables' => $tables, 'table' => $table, 'columns' => $columns, 'data' => $data, 'pageNo' => $page,
            'pages' => max(1, (int) ceil($data['total'] / 50)),
            'sort' => $request->query('sort'), 'dir' => $request->query('dir') === 'desc' ? 'desc' : 'asc',
        ]);
    }

    public function query(Request $request, AppServer $app, AppDatabase $database): RedirectResponse
    {
        $this->authorizeDatabase($app, $database, 'operate');
        $data = $request->validate(['sql' => ['required', 'string', 'max:100000']]);
        $back = redirect()->route('panel.apps.databases.browse', [$app, $database])->with('sql', $data['sql']);
        try {
            $result = (new DatabaseBrowser($database))->run($data['sql']);
        } catch (DatabaseException $e) {
            return $back->withErrors(['sql' => $e->getMessage()]);
        }
        AuditLog::record('app.database_query', $app, ['database' => $database->database, 'statements' => $result['statements']], $request->user());

        return $back->with('result', $result);
    }

    public function export(Request $request, AppServer $app, AppDatabase $database): StreamedResponse|RedirectResponse
    {
        $this->authorizeDatabase($app, $database, 'operate');
        try {
            $browser = new DatabaseBrowser($database);
        } catch (DatabaseException $e) {
            return back()->withErrors(['database' => $e->getMessage()]);
        }
        AuditLog::record('app.database_export', $app, ['database' => $database->database], $request->user());

        return response()->streamDownload(function () use ($browser) {
            $browser->export(function (string $chunk) {
                echo $chunk;
                flush();
            });
        }, $database->database.'-'.now()->format('Ymd-His').'.sql', ['Content-Type' => 'application/sql; charset=utf-8']);
    }

    public function import(Request $request, AppServer $app, AppDatabase $database): RedirectResponse
    {
        $this->authorizeDatabase($app, $database, 'operate');
        $request->validate(['file' => ['required', 'file', 'max:51200', function ($attr, $file, $fail) {
            if (! in_array(strtolower($file->getClientOriginalExtension()), ['sql', 'txt'], true)) {
                $fail(__('Wybierz plik .sql.'));
            }
        }]]);
        try {
            $count = (new DatabaseBrowser($database))->import((string) file_get_contents($request->file('file')->getRealPath()));
        } catch (DatabaseException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }
        AuditLog::record('app.database_import', $app, ['database' => $database->database, 'statements' => $count], $request->user());

        return back()->with('status', __('Zaimportowano zapytania: :count.', ['count' => $count]));
    }

    private function page(AppServer $app, AppDatabase $database): array
    {
        $app->load(['egg', 'plan', 'allocations', 'hypervisor', 'user']);

        return ['app' => $app, 'tab' => 'databases', 'database' => $database];
    }

    private function authorizeDatabase(AppServer $app, AppDatabase $database, string $ability): void
    {
        $this->authorize($ability, $app);
        abort_unless($database->app_server_id === $app->id, 404);
    }
}
