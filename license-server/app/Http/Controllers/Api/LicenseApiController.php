<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Licensing\Signer;
use App\Models\Addon;
use App\Models\License;
use App\Models\LicenseEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * API dla paneli VirtHub. Każde zapytanie niesie klucz licencji i domenę
 * panelu. Licencja przypina się do domeny przy pierwszej aktywacji; inną
 * domenę może ustawić tylko administrator serwera licencji.
 */
class LicenseApiController extends Controller
{
    public function __construct(private readonly Signer $signer) {}

    public function publicKey(): JsonResponse
    {
        return response()->json(['public_key' => $this->signer->publicKey()]);
    }

    /** Podpisany token licencji (stan, ważność, addony). */
    public function verify(Request $request): JsonResponse
    {
        [$license, $error] = $this->resolve($request, requireUsable: false);
        if ($error) {
            return $error;
        }
        $license->update([
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
            'panel_version' => mb_substr((string) $request->input('panel_version'), 0, 60) ?: null,
        ]);

        $addons = [];
        if ($license->isUsable()) {
            foreach ($license->activeAddons() as $addon) {
                $addons[$addon->slug] = ['name' => $addon->name, 'version' => $addon->latest()?->version];
            }
        }

        return response()->json($this->signer->token([
            'licensee' => $license->owner_name,
            'domain' => $license->domain,
            'status' => $license->status,
            'expires_at' => $license->expires_at?->toIso8601String(),
            'addons' => (object) $addons,
            'issued_at' => now()->getTimestamp(),
        ]));
    }

    /** Katalog addonów z informacją, które ma ta licencja. */
    public function catalog(Request $request): JsonResponse
    {
        [$license, $error] = $this->resolve($request);
        if ($error) {
            return $error;
        }
        $owned = $license->activeAddons()->pluck('id')->all();

        return response()->json(['addons' => Addon::query()->with('versions')->orderBy('name')->get()
            ->filter(fn (Addon $a) => $a->is_public || in_array($a->id, $owned, true))
            ->map(fn (Addon $a) => [
                'id' => $a->slug, 'name' => $a->name, 'description' => $a->description, 'price' => $a->price,
                'version' => $a->latest()?->version, 'owned' => in_array($a->id, $owned, true),
            ])->values()]);
    }

    /** Najnowsza wersja addonu: paczka + suma + podpis. */
    public function download(Request $request, string $slug): JsonResponse
    {
        [$license, $error] = $this->resolve($request);
        if ($error) {
            return $error;
        }
        $addon = $license->activeAddons()->firstWhere('slug', $slug);
        if ($addon === null) {
            return response()->json(['message' => 'Licencja nie obejmuje tego addonu.'], 403);
        }
        $version = $addon->load('versions')->latest();
        if ($version === null) {
            return response()->json(['message' => 'Addon nie ma jeszcze opublikowanej wersji.'], 404);
        }
        LicenseEvent::record($license, 'addon.download', ['addon' => $slug, 'version' => $version->version]);

        return response()->json([
            'version' => $version->version,
            'sha256' => $version->sha256,
            'signature' => $version->signature,
            'package' => base64_encode((string) Storage::disk(config('licensing.packages_disk'))->get($version->path)),
        ]);
    }

    /** @return array{0: ?License, 1: ?JsonResponse} */
    private function resolve(Request $request, bool $requireUsable = true): array
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:40'],
            'domain' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+$/i'],
        ]);
        $license = License::query()->with('addons.versions')->where('key', strtoupper(trim($data['key'])))->first();
        if ($license === null) {
            LicenseEvent::record(null, 'verify.unknown_key', ['domain' => $data['domain']]);

            return [null, response()->json(['message' => 'Licencja nie istnieje.'], 404)];
        }
        $domain = strtolower($data['domain']);
        if ($license->domain === null) {
            $license->update(['domain' => $domain]);
            LicenseEvent::record($license, 'domain.bound', ['domain' => $domain]);
        } elseif ($license->domain !== $domain) {
            LicenseEvent::record($license, 'verify.domain_mismatch', ['domain' => $domain]);

            return [null, response()->json(['message' => "Licencja jest przypisana do domeny {$license->domain}. Poproś o przeniesienie."], 403)];
        }
        if ($requireUsable && ! $license->isUsable()) {
            return [null, response()->json(['message' => 'Licencja jest zawieszona albo wygasła.'], 403)];
        }

        return [$license, null];
    }
}
