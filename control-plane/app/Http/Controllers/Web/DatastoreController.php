<?php

namespace App\Http\Controllers\Web;

use App\Domain\Storage\Datastores;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Datastore;
use App\Models\Hypervisor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Infrastruktura → Magazyny danych: katalogi qcow2 i pule Ceph RBD na węzłach. */
class DatastoreController extends Controller
{
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    private const MONITOR = '/^\[?[A-Za-z0-9.:-]{1,253}\]?(:\d{1,5})?$/';

    public function __construct(private readonly Datastores $datastores) {}

    public function index(Request $request): View
    {
        $type = in_array($request->query('type'), Datastore::TYPES, true) ? $request->query('type') : Datastore::TYPE_DIRECTORY;

        return view('panel.admin.datastores', [
            'datastores' => Datastore::query()->with('hypervisors')->withCount(['servers', 'packages'])->orderBy('name')->get(),
            'nodes' => Hypervisor::query()->where('virtualization', 'kvm')->orderBy('name')->get(['id', 'name']),
            'newType' => $type,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $nodes] = $this->data($request);
        $datastore = Datastore::create($data);
        $datastore->hypervisors()->sync($nodes);
        AuditLog::record('datastore.created', $datastore, ['name' => $datastore->name, 'type' => $datastore->type, 'nodes' => $nodes]);
        $this->datastores->refresh($datastore->load('hypervisors'));

        return redirect()->route('panel.admin.datastores')->with('status', __('Dodano magazyn :name.', ['name' => $datastore->name]));
    }

    public function update(Request $request, Datastore $datastore): RedirectResponse
    {
        [$data, $nodes] = $this->data($request, $datastore);
        $removed = $datastore->hypervisors()->pluck('hypervisors.id')->diff($nodes);
        if ($removed->isNotEmpty() && $datastore->servers()->whereIn('hypervisor_id', $removed)->exists()) {
            throw ValidationException::withMessages(['hypervisors' => __('Na odpinanym węźle są maszyny z dyskami w tym magazynie — przenieś je albo usuń najpierw.')]);
        }
        $datastore->update($data);
        $datastore->hypervisors()->sync($nodes);
        AuditLog::record('datastore.updated', $datastore, ['name' => $datastore->name, 'nodes' => $nodes]);

        return back()->with('status', __('Zapisano magazyn :name.', ['name' => $datastore->name]));
    }

    public function refresh(Datastore $datastore): RedirectResponse
    {
        $ok = $this->datastores->refresh($datastore->load('hypervisors'));
        $total = $datastore->hypervisors->count();

        $message = __('Odczytano pojemność z :ok z :total węzłów.', ['ok' => $ok, 'total' => $total]);

        return $ok === $total ? back()->with('status', $message) : back()->withErrors(['datastore' => $message]);
    }

    public function destroy(Datastore $datastore): RedirectResponse
    {
        if ($datastore->servers()->exists()) {
            return back()->withErrors(['datastore' => __('W magazynie są dyski maszyn — usuń albo przenieś je najpierw.')]);
        }
        AuditLog::record('datastore.deleted', $datastore, ['name' => $datastore->name]);
        $datastore->delete();

        return back()->with('status', __('Magazyn usunięty. Pakiety, które go używały, tworzą maszyny w katalogu domyślnym węzła.'));
    }

    /** @return array{0: array<string, mixed>, 1: list<int>} */
    private function data(Request $request, ?Datastore $existing = null): array
    {
        $type = $existing?->type ?? $request->input('type');
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'type' => [$existing ? 'nullable' : 'required', Rule::in(Datastore::TYPES)],
            'hypervisors' => ['nullable', 'array'],
            'hypervisors.*' => ['integer', Rule::exists('hypervisors', 'id')->where('virtualization', 'kvm')],
        ];
        $rules += $type === Datastore::TYPE_CEPH ? [
            'config.monitors' => ['required', 'string', 'max:1000'],
            'config.pool' => ['required', 'regex:'.self::NAME],
            'config.user' => ['nullable', 'regex:'.self::NAME],
            'config.key' => [$existing ? 'nullable' : 'required', 'regex:/^[A-Za-z0-9+\/=]{16,128}$/'],
        ] : [
            'config.path' => ['required', 'string', 'max:250', 'regex:#^/[A-Za-z0-9._/-]+$#', 'not_regex:#(^|/)\.\.(/|$)#'],
        ];
        $data = $request->validate($rules, [
            'config.key.regex' => __('Klucz Cepha to ciąg base64 z pliku keyring (pole key = …).'),
        ]);
        $c = (array) ($data['config'] ?? []);

        if ($type === Datastore::TYPE_CEPH) {
            $monitors = array_values(array_filter(preg_split('/[\s,;]+/', (string) $c['monitors']) ?: []));
            if ($monitors === [] || count($monitors) > 9 || array_filter($monitors, fn ($m) => ! preg_match(self::MONITOR, $m))) {
                throw ValidationException::withMessages(['config.monitors' => __('Podaj od 1 do 9 monitorów Cepha (adres albo adres:port), oddzielonych przecinkami.')]);
            }
            $config = ['monitors' => $monitors, 'pool' => $c['pool'], 'user' => ($c['user'] ?? '') ?: 'admin',
                'key' => ($c['key'] ?? '') ?: ((array) $existing?->config)['key'] ?? ''];
        } else {
            $config = ['path' => rtrim((string) $c['path'], '/') ?: '/'];
        }

        return [[
            'name' => $data['name'],
            'type' => $type,
            'thin' => $type === Datastore::TYPE_CEPH || $request->boolean('thin'),
            'config' => $config,
            'is_active' => $request->boolean('is_active'),
        ], array_map('intval', $data['hypervisors'] ?? [])];
    }
}
