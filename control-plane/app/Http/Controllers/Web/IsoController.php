<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\IsoLibrary;
use App\Http\Controllers\Controller;
use App\Models\IsoImage;
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
            'isos' => IsoImage::query()->with('downloads.hypervisor')->withCount('servers')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url:http,https', 'max:2000'],
            'sha256' => ['nullable', 'regex:/^[0-9a-fA-F]{64}$/'],
            'is_public' => ['sometimes', 'boolean'],
        ], ['sha256.regex' => 'Suma SHA-256 to 64 znaki szesnastkowe.']);

        $iso = $this->library->add([...$data, 'is_public' => $request->boolean('is_public')], $request->user());

        return back()->with('status', "Dodano {$iso->name} — węzły KVM pobierają obraz.");
    }

    public function retry(IsoImage $iso): RedirectResponse
    {
        $queued = $this->library->distribute($iso, retryFailed: true);

        return back()->with('status', $queued ? "Ponowiono pobieranie na {$queued} węzłach." : 'Wszystkie węzły mają już ten obraz albo go pobierają.');
    }

    public function toggle(IsoImage $iso): RedirectResponse
    {
        $iso->update(['is_public' => ! $iso->is_public]);

        return back()->with('status', $iso->is_public ? "{$iso->name} jest widoczny dla klientów." : "{$iso->name} jest teraz tylko dla personelu.");
    }

    public function destroy(Request $request, IsoImage $iso): RedirectResponse
    {
        $this->library->delete($iso, $request->user());

        return back()->with('status', "Usunięto {$iso->name}.");
    }
}
