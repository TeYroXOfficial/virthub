<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OsTemplate;
use App\Models\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Administracja → Provisionery: skrypty pierwszego uruchomienia przypinane do szablonów. */
class ProvisionerController extends Controller
{
    public function index(): View
    {
        return view('panel.admin.provisioners.index', [
            'provisioners' => Provisioner::query()->withCount('templates')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Provisioner(['content' => "#!/bin/bash\nset -euo pipefail\n\n"]));
    }

    public function edit(Provisioner $provisioner): View
    {
        return $this->form($provisioner);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $templates, $order] = $this->validated($request);
        $provisioner = Provisioner::create($data);
        $this->syncTemplates($provisioner, $templates, $order);
        AuditLog::record('provisioner.created', $provisioner, ['name' => $provisioner->name]);

        return redirect()->route('panel.admin.provisioners.edit', $provisioner)->with('status', __('Provisioner zapisany.'));
    }

    public function update(Request $request, Provisioner $provisioner): RedirectResponse
    {
        [$data, $templates, $order] = $this->validated($request, $provisioner);
        $provisioner->update($data);
        $this->syncTemplates($provisioner, $templates, $order);
        AuditLog::record('provisioner.updated', $provisioner, ['name' => $provisioner->name, 'templates' => count($templates)]);

        return back()->with('status', __('Provisioner zapisany.'));
    }

    public function destroy(Provisioner $provisioner): RedirectResponse
    {
        AuditLog::record('provisioner.deleted', $provisioner, ['name' => $provisioner->name]);
        $provisioner->delete();

        return redirect()->route('panel.admin.provisioners')->with('status', __('Provisioner usunięty.'));
    }

    private function form(Provisioner $provisioner): View
    {
        return view('panel.admin.provisioners.form', [
            'provisioner' => $provisioner,
            'templates' => $this->eligibleTemplates(),
            'attached' => $provisioner->exists ? $provisioner->templates()->pluck('os_templates.id')->all() : [],
            'order' => $provisioner->exists ? (int) $provisioner->templates()->max('sort_order') : 0,
        ]);
    }

    /** Linux (KVM i kontenery) — cloudbase-init w Windows nie wykonuje tych skryptów. */
    private function eligibleTemplates(): Collection
    {
        return OsTemplate::query()->where(fn ($q) => $q->whereNull('family')->orWhere('family', '!=', 'windows'))
            ->orderBy('name')->orderBy('version')->get();
    }

    private function syncTemplates(Provisioner $provisioner, array $templates, int $order): void
    {
        $provisioner->templates()->sync(collect($templates)->mapWithKeys(fn ($id) => [$id => ['sort_order' => $order]])->all());
    }

    /** @return array{0: array<string, mixed>, 1: list<int>, 2: int} */
    private function validated(Request $request, ?Provisioner $provisioner = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,40}$/', Rule::unique('provisioners', 'name')->ignore($provisioner?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string', 'max:65536', 'starts_with:#!'],
            'path' => ['required', 'string', 'max:200', 'regex:#^/[A-Za-z0-9._/-]{1,200}$#', 'not_regex:#(^|/)\.\.(/|$)#'],
            'templates' => ['nullable', 'array'],
            'templates.*' => ['integer', Rule::in($this->eligibleTemplates()->pluck('id')->all())],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ], [
            'name.regex' => __('Nazwa: małe litery, cyfry, - i _ (np. docker-install).'),
            'content.starts_with' => __('Skrypt musi zaczynać się od linii #! (np. #!/bin/bash).'),
        ]);
        // Skrypt z Windowsowymi końcami linii nie uruchomi się (#!/bin/bash\r).
        $content = str_replace("\r\n", "\n", $data['content']);

        return [
            ['name' => $data['name'], 'description' => $data['description'] ?? null, 'content' => $content,
                'path' => rtrim($data['path'], '/') ?: '/', 'is_active' => $request->boolean('is_active')],
            array_values(array_unique(array_map('intval', $data['templates'] ?? []))),
            (int) ($data['sort_order'] ?? 0),
        ];
    }
}
