<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Apps\Databases\DatabaseException;
use App\Domain\Apps\Databases\DatabaseServer;
use App\Domain\Apps\Databases\NodeDatabaseInstaller;
use App\Http\Controllers\Controller;
use App\Models\AppDatabase;
use App\Models\AuditLog;
use App\Models\DatabaseHost;
use App\Models\Hypervisor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Administracja → Aplikacje → Bazy danych: serwery MySQL/MariaDB i bazy klientów. */
class DatabaseHostController extends Controller
{
    public function __construct(private readonly DatabaseServer $server, private readonly NodeDatabaseInstaller $installer) {}

    public function index(): View
    {
        if ($this->installer->pending()) {
            $this->installer->syncPending();
        }

        return view('panel.admin.apps.databases', [
            'installs' => $this->installer->all(),
            'installPending' => $this->installer->pending(),
            'hosts' => DatabaseHost::query()->with('hypervisor')->withCount('databases')->orderBy('name')->get(),
            'databases' => AppDatabase::query()->with('app.user', 'host')->latest('id')->paginate(30),
            'nodes' => Hypervisor::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $host = new DatabaseHost($data);
        $host->setSecret($request->string('password'));
        if ($error = $this->probe($host)) {
            return back()->withInput()->withErrors(['host' => $error]);
        }
        $host->save();
        AuditLog::record('database_host.created', $host, ['host' => $host->host], $request->user());

        return back()->with('status', __('Serwer baz :name dodany.', ['name' => $host->name]));
    }

    public function update(Request $request, DatabaseHost $host): RedirectResponse
    {
        $data = $this->validated($request, false, 'edit_'.$host->id);
        $host->fill($data);
        if ($request->filled('password')) {
            $host->setSecret($request->string('password'));
        }
        if ($error = $this->probe($host)) {
            return back()->withInput()->withErrors(['host' => $error], 'edit_'.$host->id);
        }
        $host->save();
        AuditLog::record('database_host.updated', $host, ['host' => $host->host], $request->user());

        return back()->with('status', __('Serwer baz :name zapisany.', ['name' => $host->name]));
    }

    /** „Zainstaluj MariaDB na węźle” — reszta dzieje się sama (patrz NodeDatabaseInstaller). */
    public function install(Request $request): RedirectResponse
    {
        $data = $request->validate(['hypervisor_id' => ['required', 'integer', 'exists:hypervisors,id']]);
        $node = Hypervisor::query()->findOrFail($data['hypervisor_id']);
        try {
            $this->installer->start($node, $request->boolean('open_firewall'), $request->user());
        } catch (AgentException $e) {
            return back()->withErrors(['install' => $e->getMessage()]);
        }

        return back()->with('status', __('Zlecono instalację MariaDB na :node. Serwer baz pojawi się na liście sam, gdy instalacja się skończy.', ['node' => $node->name]));
    }

    public function dismissInstall(Hypervisor $node): RedirectResponse
    {
        $this->installer->dismiss($node);

        return back();
    }

    public function test(DatabaseHost $host): RedirectResponse
    {
        $error = $this->probe($host);

        return $error ? back()->withErrors(['host' => $error]) : back()->with('status', __('Połączono z :name — :version.', ['name' => $host->name, 'version' => $this->server->version($host)]));
    }

    public function destroy(Request $request, DatabaseHost $host): RedirectResponse
    {
        if ($host->databases()->exists()) {
            return back()->withErrors(['host' => __('Na serwerze są bazy klientów — usuń je albo wyłącz serwer zamiast usuwać.')]);
        }
        AuditLog::record('database_host.deleted', $host, ['host' => $host->host], $request->user());
        $host->delete();

        return back()->with('status', __('Serwer baz usunięty.'));
    }

    private function probe(DatabaseHost $host): ?string
    {
        try {
            $this->server->version($host);

            return null;
        } catch (DatabaseException $e) {
            return $e->getMessage();
        }
    }

    private function validated(Request $request, bool $creating, ?string $bag = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:_-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'public_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:_-]+$/'],
            'username' => ['required', 'string', 'max:64'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'hypervisor_id' => ['nullable', 'integer', 'exists:hypervisors,id'],
            'max_databases' => ['nullable', 'integer', 'min:1'],
        ];
        $data = $bag ? $request->validateWithBag($bag, $rules) : $request->validate($rules);
        unset($data['password']);

        return $data + ['is_active' => $request->boolean('is_active', true)];
    }
}
