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

/** Infrastruktura → Magazyny danych: katalogi, zasoby sieciowe (NFS, SMB, CephFS, iSCSI) i dyski blokowe (Ceph, StorPool, Lightbits). */
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
        $old = (array) $existing?->config;
        $secret = fn (string $pattern) => [$existing ? 'nullable' : 'required', 'string', $pattern];
        $optionalSecret = ['nullable', 'string', 'regex:/^[^\x00-\x1f,]{1,256}$/'];
        $host = ['required', 'string', 'max:260', 'regex:'.self::MONITOR];
        $options = ['nullable', 'string', 'max:250', 'regex:#^[A-Za-z0-9_=.,:/-]*$#'];
        $export = ['string', 'max:250', 'regex:#^/?[A-Za-z0-9._/ $-]+$#', 'not_regex:#(^|/)\.\.(/|$)#'];
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'type' => [$existing ? 'nullable' : 'required', Rule::in(Datastore::TYPES)],
            'hypervisors' => ['nullable', 'array'],
            'hypervisors.*' => ['integer', Rule::exists('hypervisors', 'id')->where('virtualization', 'kvm')],
        ];
        $rules += match ($type) {
            Datastore::TYPE_CEPH => [
                'config.monitors' => ['required', 'string', 'max:1000'],
                'config.pool' => ['required', 'regex:'.self::NAME],
                'config.user' => ['nullable', 'regex:'.self::NAME],
                'config.key' => $secret('regex:/^[A-Za-z0-9+\/=]{16,128}$/'),
            ],
            Datastore::TYPE_CEPHFS => [
                'config.monitors' => ['required', 'string', 'max:1000'],
                'config.export' => ['nullable', ...$export],
                'config.user' => ['nullable', 'regex:'.self::NAME],
                'config.key' => $secret('regex:/^[A-Za-z0-9+\/=]{16,128}$/'),
                'config.options' => $options,
            ],
            Datastore::TYPE_NFS, Datastore::TYPE_CIFS => [
                'config.server' => $host,
                'config.export' => ['required', ...$export],
                'config.options' => $options,
                'config.username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._@\\\\-]{1,128}$/'],
                'config.password' => $optionalSecret,
            ],
            Datastore::TYPE_ISCSI => [
                'config.portal' => $host,
                'config.iqn' => ['required', 'string', 'regex:/^(iqn\.|eui\.|naa\.)[A-Za-z0-9.:_-]{1,220}$/'],
                'config.lun' => ['nullable', 'integer', 'min:0', 'max:16383'],
                'config.options' => $options,
                'config.username' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._@-]{1,128}$/'],
                'config.password' => $optionalSecret,
            ],
            Datastore::TYPE_STORPOOL => [
                'config.template' => ['required', 'regex:'.self::NAME],
            ],
            Datastore::TYPE_LIGHTBITS => [
                'config.endpoint' => $host,
                'config.nqn' => ['required', 'string', 'regex:/^nqn\.[A-Za-z0-9.:_-]{1,220}$/'],
                'config.targets' => ['required', 'string', 'max:1000'],
                'config.project' => ['nullable', 'regex:'.self::NAME],
                'config.replicas' => ['nullable', 'integer', 'min:1', 'max:3'],
                'config.jwt' => $secret('regex:/^[A-Za-z0-9._-]{16,8192}$/'),
            ],
            default => [
                'config.path' => ['required', 'string', 'max:250', 'regex:#^/[A-Za-z0-9._/-]+$#', 'not_regex:#(^|/)\.\.(/|$)#'],
            ],
        };
        $data = $request->validate($rules, [
            'config.key.regex' => __('Klucz Cepha to ciąg base64 z pliku keyring (pole key = …).'),
        ]);
        $c = array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) ($data['config'] ?? []));
        $list = function (string $field, string $message) use ($c): array {
            $items = array_values(array_filter(preg_split('/[\s,;]+/', (string) ($c[$field] ?? '')) ?: []));
            if ($items === [] || count($items) > 9 || array_filter($items, fn ($m) => ! preg_match(self::MONITOR, $m))) {
                throw ValidationException::withMessages(["config.$field" => $message]);
            }

            return $items;
        };
        $monitorsMessage = __('Podaj od 1 do 9 monitorów Cepha (adres albo adres:port), oddzielonych przecinkami.');

        $config = match ($type) {
            Datastore::TYPE_CEPH => ['monitors' => $list('monitors', $monitorsMessage), 'pool' => $c['pool'], 'user' => ($c['user'] ?? '') ?: 'admin'],
            Datastore::TYPE_CEPHFS => ['monitors' => $list('monitors', $monitorsMessage), 'export' => ($c['export'] ?? '') ?: '/',
                'user' => ($c['user'] ?? '') ?: 'admin', 'options' => $c['options'] ?? ''],
            Datastore::TYPE_NFS, Datastore::TYPE_CIFS => ['server' => $c['server'], 'export' => $c['export'], 'options' => $c['options'] ?? '',
                'username' => ($c['username'] ?? '') ?: null],
            Datastore::TYPE_ISCSI => ['portal' => $c['portal'], 'iqn' => $c['iqn'], 'lun' => (int) ($c['lun'] ?? 0), 'options' => $c['options'] ?? '',
                'username' => ($c['username'] ?? '') ?: null],
            Datastore::TYPE_STORPOOL => ['template' => $c['template']],
            Datastore::TYPE_LIGHTBITS => ['endpoint' => $c['endpoint'], 'nqn' => $c['nqn'],
                'targets' => $list('targets', __('Podaj od 1 do 9 adresów danych Lightbits (adres albo adres:port), oddzielonych przecinkami.')),
                'project' => ($c['project'] ?? '') ?: 'default', 'replicas' => (int) (($c['replicas'] ?? null) ?: 2)],
            default => ['path' => rtrim((string) $c['path'], '/') ?: '/'],
        };
        // Sekrety: puste pole przy edycji = bez zmiany.
        foreach (Datastore::SECRETS as $field) {
            if (array_key_exists("config.$field", $rules)) {
                $value = ($c[$field] ?? '') ?: ($old[$field] ?? null);
                if ($value !== null && $value !== '') {
                    $config[$field] = $value;
                }
            }
        }

        return [[
            'name' => $data['name'],
            'type' => $type,
            'thin' => in_array($type, Datastore::BLOCK, true) || $request->boolean('thin'),
            'config' => $config,
            'is_active' => $request->boolean('is_active'),
        ], array_map('intval', $data['hypervisors'] ?? [])];
    }
}
