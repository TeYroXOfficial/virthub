<?php

namespace App\Licensing;

use App\Models\Addon;
use App\Models\AddonVersion;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/** Wgrywa paczkę addonu: sprawdza addon.json, liczy SHA-256, podpisuje i zapisuje. */
class AddonPublisher
{
    public function __construct(private readonly Signer $signer) {}

    public function publish(Addon $addon, string $zipPath, ?string $changelog = null): AddonVersion
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Plik nie jest archiwum zip.');
        }
        $manifest = json_decode((string) $zip->getFromName('addon.json'), true);
        $zip->close();
        if (! is_array($manifest) || ($manifest['id'] ?? null) !== $addon->slug) {
            throw new RuntimeException("addon.json musi mieć \"id\": \"{$addon->slug}\".");
        }
        $version = (string) ($manifest['version'] ?? '');
        if (! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new RuntimeException('addon.json: nieprawidłowa wersja (np. 1.0.0).');
        }
        if ($addon->versions()->where('version', $version)->exists()) {
            throw new RuntimeException("Wersja {$version} już istnieje — podnieś numer w addon.json.");
        }

        $data = (string) file_get_contents($zipPath);
        $sha = hash('sha256', $data);
        $path = "addons/{$addon->slug}/{$addon->slug}-{$version}.zip";
        Storage::disk(config('licensing.packages_disk'))->put($path, $data);

        return $addon->versions()->create([
            'version' => $version,
            'sha256' => $sha,
            'signature' => $this->signer->sign(Signer::packageMessage($addon->slug, $version, $sha)),
            'path' => $path,
            'size' => strlen($data),
            'changelog' => $changelog,
            'is_published' => true,
        ]);
    }
}
