<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OsTemplate;
use App\Models\VpsPackage;
use Illuminate\Http\JsonResponse;

/** Katalog oferty: co klient może zamówić i na czym to postawić. */
class CatalogController extends Controller
{
    public function packages(): JsonResponse
    {
        $packages = VpsPackage::query()->active()->orderBy('vcpu')->get();

        return response()->json([
            'data' => $packages->map(fn (VpsPackage $package) => [
                'slug' => $package->slug,
                'name' => $package->name,
                'description' => $package->description,
                'vcpu' => $package->vcpu,
                'ram_mb' => $package->ram_mb,
                'ram_gb' => $package->ramGb(),
                'disk_gb' => $package->disk_gb,
                'bandwidth_gb' => $package->bandwidth_gb,
                'ip_count' => $package->ip_count,
                'price_hint' => $package->price_hint_cents !== null
                    ? round($package->price_hint_cents / 100, 2)
                    : null,
                'currency' => $package->currency,
            ]),
        ]);
    }

    public function templates(): JsonResponse
    {
        $templates = OsTemplate::query()->active()->orderBy('family')->orderByDesc('version')->get();

        return response()->json([
            'data' => $templates->map(fn (OsTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'family' => $template->family,
                'version' => $template->version,
                'min_disk_gb' => $template->min_disk_gb,
                'self_service' => $template->isSelfService(),
            ]),
        ]);
    }
}
