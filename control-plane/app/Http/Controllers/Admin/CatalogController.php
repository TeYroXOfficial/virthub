<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\VpsPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Zarządzanie ofertą: pakiety zasobów i szablony systemów. */
class CatalogController extends Controller
{
    // --- pakiety ------------------------------------------------------------

    public function storePackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:vps_packages,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'vcpu' => ['required', 'integer', 'min:1', 'max:128'],
            'ram_mb' => ['required', 'integer', 'min:512'],
            'disk_gb' => ['required', 'integer', 'min:5'],
            'bandwidth_gb' => ['required', 'integer', 'min:0'],
            'ip_count' => ['required', 'integer', 'min:1', 'max:16'],
            'ipv6_count' => ['nullable', 'integer', 'min:0', 'max:16'],
            'network_type' => ['nullable', Rule::in(IpPool::TYPES)],
            'price_hint_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $package = VpsPackage::create([
            ...$validated,
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'currency' => $validated['currency'] ?? 'PLN',
            'ipv6_count' => $validated['ipv6_count'] ?? 0,
            'network_type' => $validated['network_type'] ?? IpPool::TYPE_PUBLIC,
        ]);

        AuditLog::record('package.created', $package, ['slug' => $package->slug]);

        return response()->json(['data' => $package], 201);
    }

    public function updatePackage(Request $request, VpsPackage $package): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'price_hint_cents' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            // Parametrów zasobów nie da się zmienić po utworzeniu pakietu:
            // działające maszyny mają skopiowane wartości, a zmiana w cenniku
            // stworzyłaby rozjazd między tym, co klient widzi, a co ma.
        ]);

        $package->update($validated);
        AuditLog::record('package.updated', $package, $validated);

        return response()->json(['data' => $package->fresh()]);
    }

    public function destroyPackage(VpsPackage $package): JsonResponse
    {
        if ($package->servers()->exists()) {
            // Wyłączamy zamiast usuwać — inaczej stracilibyśmy nazwę pakietu
            // przy maszynach, które go używają.
            $package->update(['is_active' => false]);

            return response()->json([
                'message' => 'Pakiet jest używany przez istniejące maszyny, więc został '
                    .'wyłączony ze sprzedaży zamiast usunięty.',
            ]);
        }

        AuditLog::record('package.deleted', $package, ['slug' => $package->slug]);
        $package->delete();

        return response()->json(['message' => 'Pakiet został usunięty.']);
    }

    // --- szablony -----------------------------------------------------------

    public function storeTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'family' => ['required', Rule::in(array_keys(\App\Models\OsTemplateGroup::FAMILIES))],
            'os_template_group_id' => ['nullable', 'integer', 'exists:os_template_groups,id'],
            'version' => ['required', 'string', 'max:32'],
            // Sama nazwa pliku, bez ścieżki — układ katalogów należy do agenta,
            // a wartość ze ścieżką pozwoliłaby sięgnąć poza katalog szablonów.
            'image_file' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'min_disk_gb' => ['required', 'integer', 'min:1'],
            'cloud_init_support' => ['boolean'],
        ]);

        $template = OsTemplate::create($validated);
        AuditLog::record('template.created', $template, ['name' => $template->name]);

        return response()->json(['data' => $template], 201);
    }

    public function updateTemplate(Request $request, OsTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'os_template_group_id' => ['sometimes', 'nullable', 'integer', 'exists:os_template_groups,id'],
            'is_active' => ['sometimes', 'boolean'],
            'min_disk_gb' => ['sometimes', 'integer', 'min:1'],
        ]);

        $template->update($validated);
        AuditLog::record('template.updated', $template, $validated);

        return response()->json(['data' => $template->fresh()]);
    }

    public function destroyTemplate(OsTemplate $template): JsonResponse
    {
        $template->update(['is_active' => false]);

        return response()->json([
            'message' => 'Szablon został wyłączony. Maszyny na nim postawione działają dalej.',
        ]);
    }
}
