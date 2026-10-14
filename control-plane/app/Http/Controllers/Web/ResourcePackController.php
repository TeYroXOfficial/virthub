<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ResourcePack;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Oferta → Pakiety zasobów (samoobsługa). */
class ResourcePackController extends Controller
{
    public function index(): View
    {
        return view('panel.admin.resource-packs', [
            'packs' => ResourcePack::query()->with(['user:id,email', 'package:id,name'])->withCount('servers')->latest('id')->get(),
            'packages' => VpsPackage::query()->orderBy('vcpu')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $user = User::query()->where('email', mb_strtolower($request->string('email')->value()))->first();
        if ($user === null) {
            return back()->withErrors(['email' => __('Nie ma konta z takim adresem e-mail.')])->withInput();
        }
        $pack = $user->resourcePacks()->create($data);
        AuditLog::record('resource_pack.created', $pack, ['user' => $user->email, 'name' => $pack->name]);

        return back()->with('status', __('Pakiet zasobów :name przydzielony :email.', ['name' => $pack->name, 'email' => $user->email]));
    }

    public function update(Request $request, ResourcePack $pack): RedirectResponse
    {
        $pack->update($this->validated($request, update: true));
        AuditLog::record('resource_pack.updated', $pack, ['name' => $pack->name]);

        return back()->with('status', __('Zapisano pakiet zasobów :name.', ['name' => $pack->name]));
    }

    public function destroy(ResourcePack $pack): RedirectResponse
    {
        if ($pack->servers()->exists()) {
            return back()->withErrors(['pack' => __('Pakiet ma maszyny — klient musi je najpierw usunąć (albo usuń je w administracji).')]);
        }
        AuditLog::record('resource_pack.deleted', $pack, ['name' => $pack->name]);
        $pack->delete();

        return back()->with('status', __('Pakiet zasobów usunięty.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $update = false): array
    {
        $data = $request->validate([
            'email' => [$update ? 'nullable' : 'required', 'email'],
            'name' => ['required', 'string', 'max:100'],
            'vcpu' => ['required', 'integer', 'min:1', 'max:4096'],
            'ram_mb' => ['required', 'integer', 'min:512'],
            'disk_gb' => ['required', 'integer', 'min:1'],
            'max_servers' => ['required', 'integer', 'min:1', 'max:1000'],
            'vps_package_id' => ['required', 'integer', 'exists:vps_packages,id'],
            'price_vcpu_hour' => ['nullable', 'integer', 'min:0'],
            'price_ram_gb_hour' => ['nullable', 'integer', 'min:0'],
            'price_disk_gb_hour' => ['nullable', 'integer', 'min:0'],
        ]);
        unset($data['email']);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
