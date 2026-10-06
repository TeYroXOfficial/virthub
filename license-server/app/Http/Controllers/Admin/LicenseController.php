<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\License;
use App\Models\LicenseEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LicenseController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        return view('licenses.index', [
            'licenses' => License::query()->withCount('addons')
                ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('key', 'like', "%{$q}%")->orWhere('owner_name', 'like', "%{$q}%")
                    ->orWhere('owner_email', 'like', "%{$q}%")->orWhere('domain', 'like', "%{$q}%")))
                ->latest()->paginate(30)->withQueryString(),
            'q' => $q,
        ]);
    }

    public function create(): View
    {
        return view('licenses.form', ['license' => new License(['status' => License::ACTIVE]), 'addons' => Addon::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $license = License::query()->create($this->data($request) + ['key' => License::generateKey()]);
        $this->syncAddons($request, $license);
        LicenseEvent::record($license, 'license.created');

        return redirect()->route('licenses.edit', $license)->with('status', "Licencja {$license->key} utworzona.");
    }

    public function edit(License $license): View
    {
        return view('licenses.form', [
            'license' => $license->load('addons', 'events'),
            'addons' => Addon::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, License $license): RedirectResponse
    {
        $before = $license->domain;
        $license->update($this->data($request));
        $this->syncAddons($request, $license);
        LicenseEvent::record($license, 'license.updated', $before !== $license->domain ? ['domain' => [$before, $license->domain]] : []);

        return back()->with('status', 'Licencja zapisana.');
    }

    public function destroy(License $license): RedirectResponse
    {
        $license->delete();

        return redirect()->route('licenses.index')->with('status', 'Licencja usunięta.');
    }

    private function data(Request $request): array
    {
        $data = $request->validate([
            'owner_name' => ['required', 'string', 'max:150'],
            'owner_email' => ['nullable', 'email', 'max:190'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:/^[a-z0-9.-]+$/i'],
            'status' => ['required', Rule::in([License::ACTIVE, License::SUSPENDED])],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['integer', 'exists:addons,id'],
            'addon_expires' => ['nullable', 'array'],
            'addon_expires.*' => ['nullable', 'date'],
        ]);
        $data['domain'] = isset($data['domain']) && $data['domain'] !== '' ? strtolower($data['domain']) : null;
        unset($data['addons'], $data['addon_expires']);

        return $data;
    }

    private function syncAddons(Request $request, License $license): void
    {
        $expires = (array) $request->input('addon_expires', []);
        $license->addons()->sync(collect($request->input('addons', []))->mapWithKeys(fn ($id) => [
            (int) $id => ['expires_at' => ! empty($expires[$id]) ? $expires[$id] : null],
        ])->all());
    }
}
