<?php

namespace App\Http\Controllers\Web;

use App\Domain\Licensing\AddonException;
use App\Domain\Licensing\AddonManager;
use App\Domain\Licensing\LicenseManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Administracja → System → Licencja i addony (tylko administrator). */
class LicenseController extends Controller
{
    public function __construct(private readonly LicenseManager $license, private readonly AddonManager $addons) {}

    public function index(): View
    {
        $installed = $this->addons->installed();
        $catalog = collect($this->addons->catalog())->keyBy('id');
        // Zainstalowane, których nie ma już w katalogu (np. licencja wygasła) — też na liście.
        foreach ($installed as $id => $addon) {
            if (! $catalog->has($id)) {
                $catalog[$id] = ['id' => $id, 'name' => $addon['name'], 'description' => null, 'version' => null, 'owned' => $this->license->hasAddon($id), 'price' => null];
            }
        }

        return view('panel.admin.license', [
            'status' => $this->license->status(),
            'configured' => $this->license->configured(),
            'key' => $this->license->mask($this->license->key()),
            'domain' => $this->license->domain(),
            'catalog' => $catalog->values(),
            'installed' => $installed,
            'loaded' => $this->addons->loaded(),
        ]);
    }

    public function activate(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]+$/']]);
        $status = $this->license->activate($data['key'], $request->user());

        return $status['state'] === LicenseManager::STATE_VALID
            ? back()->with('status', __('Licencja aktywna.'))
            : back()->withErrors(['key' => $status['error'] ?? __('Licencja nie jest aktywna.')]);
    }

    public function refresh(): RedirectResponse
    {
        $status = $this->license->refresh();

        return $status['error'] ? back()->withErrors(['key' => $status['error']]) : back()->with('status', __('Licencja odświeżona.'));
    }

    public function remove(Request $request): RedirectResponse
    {
        $this->license->remove($request->user());

        return back()->with('status', __('Klucz licencji usunięty. Addony są wyłączone.'));
    }

    public function install(Request $request, string $addon): RedirectResponse
    {
        try {
            $version = $this->addons->install($addon, $request->user());
        } catch (AddonException $e) {
            return back()->withErrors(['addon' => $e->getMessage()]);
        }

        return back()->with('status', __('Addon :id :version zainstalowany.', ['id' => $addon, 'version' => $version]));
    }

    public function toggle(Request $request, string $addon): RedirectResponse
    {
        $enabled = ! ($this->addons->installed()[$addon]['enabled'] ?? false);
        try {
            $this->addons->setEnabled($addon, $enabled, $request->user());
        } catch (AddonException $e) {
            return back()->withErrors(['addon' => $e->getMessage()]);
        }

        return back()->with('status', $enabled ? __('Addon włączony.') : __('Addon wyłączony — działające serwery zostają, nowe zamówienia i akcje są wstrzymane.'));
    }

    public function uninstall(Request $request, string $addon): RedirectResponse
    {
        try {
            $this->addons->uninstall($addon, $request->user());
        } catch (AddonException $e) {
            return back()->withErrors(['addon' => $e->getMessage()]);
        }

        return back()->with('status', __('Addon odinstalowany.'));
    }
}
