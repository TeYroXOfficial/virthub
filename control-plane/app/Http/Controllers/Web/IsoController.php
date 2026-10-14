<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\IsoLibrary;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IsoImage;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Administracja → Obrazy ISO. */
class IsoController extends Controller
{
    public function __construct(private readonly IsoLibrary $library) {}

    public function index(): View
    {
        return view('panel.admin.isos', [
            'isos' => IsoImage::query()->whereNull('server_id')->with('downloads.hypervisor')->withCount('servers')->orderBy('name')->get(),
            'customLimit' => IsoLibrary::customLimit(),
            'customMaxGb' => IsoLibrary::customMaxGb(),
            'customCount' => IsoImage::query()->whereNotNull('server_id')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url:http,https', 'max:2000'],
            'sha256' => ['nullable', 'regex:/^[0-9a-fA-F]{64}$/'],
            'is_public' => ['sometimes', 'boolean'],
        ], ['sha256.regex' => __('Suma SHA-256 to 64 znaki szesnastkowe.')]);

        $iso = $this->library->add([...$data, 'is_public' => $request->boolean('is_public')], $request->user());

        return back()->with('status', __('Dodano :name — węzły KVM pobierają obraz.', ['name' => $iso->name]));
    }

    /** Własne obrazy klientów: liczba na maszynę (0 = wyłączone) i limit rozmiaru. */
    public function customSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'custom_limit' => ['required', 'integer', 'min:0', 'max:10'],
            'custom_max_gb' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        Setting::put(['iso.custom_limit' => (string) $data['custom_limit'], 'iso.custom_max_gb' => (string) $data['custom_max_gb']]);
        AuditLog::record('settings.custom_iso', null, $data);

        return back()->with('status', __('Zapisano ustawienia własnych obrazów klientów.'));
    }

    public function retry(IsoImage $iso): RedirectResponse
    {
        $queued = $this->library->distribute($iso, retryFailed: true);

        return back()->with('status', $queued ? __('Ponowiono pobieranie na :queued węzłach.', ['queued' => $queued]) : __('Wszystkie węzły mają już ten obraz albo go pobierają.'));
    }

    public function toggle(IsoImage $iso): RedirectResponse
    {
        $iso->update(['is_public' => ! $iso->is_public]);

        return back()->with('status', $iso->is_public ? __(':name jest widoczny dla klientów.', ['name' => $iso->name]) : __(':name jest teraz tylko dla personelu.', ['name' => $iso->name]));
    }

    public function destroy(Request $request, IsoImage $iso): RedirectResponse
    {
        $this->library->delete($iso, $request->user());

        return back()->with('status', __('Usunięto :name.', ['name' => $iso->name]));
    }
}
