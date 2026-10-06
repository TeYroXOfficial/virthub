<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Licensing\AddonPublisher;
use App\Models\Addon;
use App\Models\AddonVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AddonController extends Controller
{
    public function index(): View
    {
        return view('addons.index', ['addons' => Addon::query()->withCount('licenses')->with('versions')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{1,39}$/', Rule::unique('addons', 'slug')],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'string', 'max:60'],
        ]);
        Addon::query()->create($data + ['is_public' => $request->boolean('is_public', true)]);

        return back()->with('status', "Addon {$data['slug']} dodany. Wgraj pierwszą wersję.");
    }

    public function update(Request $request, Addon $addon): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'string', 'max:60'],
        ]);
        $addon->update($data + ['is_public' => $request->boolean('is_public')]);

        return back()->with('status', 'Addon zapisany.');
    }

    public function upload(Request $request, Addon $addon, AddonPublisher $publisher): RedirectResponse
    {
        $request->validate(['package' => ['required', 'file', 'max:20480'], 'changelog' => ['nullable', 'string', 'max:5000']]);
        try {
            $version = $publisher->publish($addon, $request->file('package')->getRealPath(), $request->input('changelog'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return back()->with('status', "Opublikowano {$addon->slug} {$version->version}.");
    }

    public function toggleVersion(AddonVersion $version): RedirectResponse
    {
        $version->update(['is_published' => ! $version->is_published]);

        return back()->with('status', $version->is_published ? 'Wersja opublikowana.' : 'Wersja wycofana — panele dostaną poprzednią.');
    }
}
