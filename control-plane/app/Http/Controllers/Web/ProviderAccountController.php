<?php

namespace App\Http\Controllers\Web;

use App\Domain\External\ExternalServerManager;
use App\Domain\External\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ExternalServer;
use App\Models\ProviderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Administracja → Dostawcy zewnętrzni: konta u dostawców obsługiwanych przez addony. */
class ProviderAccountController extends Controller
{
    public function __construct(private readonly ProviderRegistry $registry, private readonly ExternalServerManager $manager) {}

    public function index(): View
    {
        return view('panel.admin.providers', [
            'accounts' => ProviderAccount::query()->withCount(['servers', 'products'])->orderBy('name')->get(),
            'drivers' => $this->registry->all(),
            'servers' => ExternalServer::query()->with('user', 'account')->latest()->limit(50)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'driver' => ['required', Rule::in(array_keys($this->registry->all()))],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $account = new ProviderAccount(['driver' => $data['driver'], 'name' => $data['name'], 'is_active' => true]);
        $account->setCredentials($this->credentials($request, $data['driver']));

        if ($error = $this->probe($account)) {
            return back()->withInput()->withErrors(['account' => $error]);
        }
        $account->save();
        AuditLog::record('provider_account.created', $account, ['driver' => $account->driver], $request->user());

        return back()->with('status', __('Konto :name dodane.', ['name' => $account->name]));
    }

    public function update(Request $request, ProviderAccount $account): RedirectResponse
    {
        $data = $request->validateWithBag('edit_'.$account->id, ['name' => ['required', 'string', 'max:100']]);
        $account->fill(['name' => $data['name'], 'is_active' => $request->boolean('is_active')]);
        $new = array_filter($this->credentials($request, $account->driver, false), fn ($v) => $v !== '');
        if ($new !== []) {
            $account->setCredentials($new + $account->credentials());
            if ($error = $this->probe($account)) {
                return back()->withErrors(['account' => $error]);
            }
        }
        $account->save();
        AuditLog::record('provider_account.updated', $account, [], $request->user());

        return back()->with('status', __('Konto :name zapisane.', ['name' => $account->name]));
    }

    public function test(ProviderAccount $account): RedirectResponse
    {
        try {
            $info = $this->manager->driver($account)->test();
        } catch (Throwable $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }

        return back()->with('status', __('Połączono z :name: :info', ['name' => $account->name, 'info' => $info]));
    }

    /** Katalog dostawcy dla formularza produktu. */
    public function catalog(Request $request, ProviderAccount $account): JsonResponse
    {
        try {
            return response()->json($this->manager->catalog($account, $request->boolean('fresh')));
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request, ProviderAccount $account): RedirectResponse
    {
        if ($account->servers()->withTrashed()->exists() || $account->products()->exists()) {
            return back()->withErrors(['account' => __('Konto ma serwery albo produkty — wyłącz je zamiast usuwać.')]);
        }
        AuditLog::record('provider_account.deleted', $account, [], $request->user());
        $account->delete();

        return back()->with('status', __('Konto usunięte.'));
    }

    /** @return array<string, string> */
    private function credentials(Request $request, string $driver, bool $creating = true): array
    {
        $fields = $this->registry->fields($driver);
        $rules = [];
        foreach ($fields as $key => $field) {
            $rules['credentials.'.$key] = [$creating && ($field['required'] ?? true) ? 'required' : 'nullable', 'string', 'max:2000'];
        }
        $data = $request->validate($rules);

        return array_map('strval', array_intersect_key($data['credentials'] ?? [], $fields));
    }

    private function probe(ProviderAccount $account): ?string
    {
        try {
            $this->registry->for($account)->test();

            return null;
        } catch (Throwable $e) {
            return __('Dostawca odrzucił dane konta: :error', ['error' => $e->getMessage()]);
        }
    }
}
