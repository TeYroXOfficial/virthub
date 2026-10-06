<?php

namespace App\Domain\Licensing;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Addony: płatne rozszerzenia z serwera licencji.
 *
 * Paczka (zip) przychodzi z podpisem Ed25519 serwera licencji nad
 * identyfikatorem, wersją i SHA-256 zawartości. Panel instaluje ją tylko,
 * gdy podpis i suma się zgadzają, a ładuje kod tylko wtedy, gdy ważna
 * licencja obejmuje addon.
 *
 * Paczka:  addon.json  {"id", "name", "version", "api": 1, "provider": "VirtHubAddons\\Onidel\\AddonServiceProvider"}
 *          src/…        klasy w przestrzeni VirtHubAddons\{Id}\ (PSR-4)
 */
class AddonManager
{
    /** Wersja API panelu dla addonów (ProviderDriver i inne punkty rozszerzeń). */
    public const API_VERSION = 1;

    private const MAX_PACKAGE_BYTES = 20 * 1024 * 1024;

    /** @var array<string, string> załadowane addony: id → wersja */
    private array $loaded = [];

    public function __construct(private readonly LicenseManager $license) {}

    public function path(?string $id = null, ?string $version = null): string
    {
        return rtrim((string) config('virthub.addons_path'), '/').($id ? '/'.$id.($version ? '/'.$version : '') : '');
    }

    /** @return array<string, array{name:string, version:string, enabled:bool, installed_at:int}> */
    public function installed(): array
    {
        $data = json_decode((string) Setting::get('addons.installed', '{}'), true);

        return is_array($data) ? $data : [];
    }

    /** @return array<string, string> */
    public function loaded(): array
    {
        return $this->loaded;
    }

    /**
     * Ładuje zainstalowane i włączone addony objęte licencją. Wołane przy starcie
     * aplikacji; błąd jednego addonu nie zatrzymuje panelu.
     */
    public function boot(): void
    {
        // Tworzenie addonów: rozpakowany katalog bez licencji, tylko lokalnie i w testach.
        // Najpierw — nie potrzebuje bazy (testy ładują aplikację przed migracjami).
        $dev = config('virthub.addon_dev_path');
        if ($dev && app()->environment('local', 'testing') && is_dir($dev)) {
            $this->load($dev, null);
        }

        foreach ($this->installed() as $id => $addon) {
            if (! ($addon['enabled'] ?? false) || isset($this->loaded[$id]) || ! $this->license->hasAddon($id)) {
                continue;
            }
            try {
                $this->load($this->path($id, (string) $addon['version']), $id);
            } catch (Throwable $e) {
                Log::error('Nie udało się załadować addonu', ['addon' => $id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Katalog addonów z serwera licencji (z informacją, które ma ta licencja).
     *
     * @return list<array{id:string, name:string, description:?string, version:?string, owned:bool, price:?string}>
     */
    public function catalog(): array
    {
        if (! $this->license->configured() || ! $this->license->key()) {
            return [];
        }
        try {
            $response = $this->license->request('POST', '/api/v1/addons');
        } catch (Throwable) {
            return [];
        }

        return $response->successful() ? array_values(array_filter((array) $response->json('addons'), 'is_array')) : [];
    }

    /** Pobiera z serwera licencji, weryfikuje i instaluje (albo aktualizuje) addon. */
    public function install(string $id, ?User $actor = null): string
    {
        $this->assertId($id);
        if (! $this->license->hasAddon($id)) {
            throw new AddonException(__('Licencja nie obejmuje addonu :id.', ['id' => $id]));
        }
        try {
            $response = $this->license->request('POST', '/api/v1/addons/'.$id.'/download', [], 120);
        } catch (Throwable $e) {
            throw new AddonException(__('Serwer licencji nie odpowiada: :error', ['error' => $e->getMessage()]));
        }
        if (! $response->successful()) {
            throw new AddonException((string) ($response->json('message') ?? __('Serwer licencji odmówił pobrania addonu.')));
        }

        $version = (string) $response->json('version');
        $package = base64_decode((string) $response->json('package'), true);

        return $this->installPackage($id, $version, $package === false ? '' : $package,
            (string) $response->json('sha256'), (string) $response->json('signature'), $actor);
    }

    /** Instalacja z gotowej paczki (sprawdzenie podpisu jak przy pobraniu). */
    public function installPackage(string $id, string $version, string $package, string $sha256, string $signature, ?User $actor = null): string
    {
        $this->assertId($id);
        if (! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new AddonException(__('Nieprawidłowy numer wersji addonu.'));
        }
        if ($package === '' || strlen($package) > self::MAX_PACKAGE_BYTES) {
            throw new AddonException(__('Paczka addonu jest pusta albo za duża.'));
        }
        if (! hash_equals(strtolower($sha256), hash('sha256', $package))) {
            throw new AddonException(__('Suma kontrolna paczki się nie zgadza — pobranie uszkodzone.'));
        }
        if (! Signature::verify(Signature::packageMessage($id, $version, strtolower($sha256)), $signature)) {
            throw new AddonException(__('Podpis paczki jest nieprawidłowy — addon nie pochodzi z serwera licencji.'));
        }

        $staging = $this->path().'/.staging-'.Str::random(12);
        $zipFile = $staging.'.zip';
        File::ensureDirectoryExists($this->path());
        file_put_contents($zipFile, $package);

        try {
            $this->extract($zipFile, $staging);
            $manifest = $this->manifest($staging);
            if ($manifest['id'] !== $id || $manifest['version'] !== $version) {
                throw new AddonException(__('Zawartość paczki nie pasuje do addonu :id :version.', ['id' => $id, 'version' => $version]));
            }
            $target = $this->path($id, $version);
            File::deleteDirectory($target);
            File::ensureDirectoryExists($this->path($id));
            File::moveDirectory($staging, $target);
        } finally {
            @unlink($zipFile);
            File::deleteDirectory($staging);
        }

        // Starsze wersje usuwamy — zostaje tylko zainstalowana.
        foreach (File::directories($this->path($id)) as $dir) {
            if (basename($dir) !== $version) {
                File::deleteDirectory($dir);
            }
        }

        $installed = $this->installed();
        $previous = $installed[$id]['version'] ?? null;
        $installed[$id] = ['name' => $manifest['name'], 'version' => $version, 'enabled' => true, 'installed_at' => time()];
        $this->save($installed);
        AuditLog::record($previous ? 'addon.updated' : 'addon.installed', null, ['addon' => $id, 'version' => $version, 'previous' => $previous], $actor);

        return $version;
    }

    public function setEnabled(string $id, bool $enabled, ?User $actor = null): void
    {
        $installed = $this->installed();
        if (! isset($installed[$id])) {
            throw new AddonException(__('Addon :id nie jest zainstalowany.', ['id' => $id]));
        }
        $installed[$id]['enabled'] = $enabled;
        $this->save($installed);
        AuditLog::record($enabled ? 'addon.enabled' : 'addon.disabled', null, ['addon' => $id], $actor);
    }

    public function uninstall(string $id, ?User $actor = null): void
    {
        $this->assertId($id);
        $installed = $this->installed();
        unset($installed[$id]);
        $this->save($installed);
        File::deleteDirectory($this->path($id));
        AuditLog::record('addon.uninstalled', null, ['addon' => $id], $actor);
    }

    /** @return array{id:string, name:string, version:string, api:int, provider:?string} */
    public function manifest(string $dir): array
    {
        $data = json_decode((string) @file_get_contents($dir.'/addon.json'), true);
        if (! is_array($data) || ! isset($data['id'], $data['name'], $data['version'])) {
            throw new AddonException(__('Paczka nie zawiera poprawnego addon.json.'));
        }
        $this->assertId((string) $data['id']);
        if ((int) ($data['api'] ?? 0) !== self::API_VERSION) {
            throw new AddonException(__('Addon wymaga innej wersji panelu (API :need, panel ma :have) — zaktualizuj panel albo addon.', ['need' => (int) ($data['api'] ?? 0), 'have' => self::API_VERSION]));
        }
        $provider = isset($data['provider']) ? (string) $data['provider'] : null;
        if ($provider !== null && ! str_starts_with($provider, $this->namespace((string) $data['id']))) {
            throw new AddonException(__('Klasa addonu musi być w przestrzeni :ns.', ['ns' => $this->namespace((string) $data['id'])]));
        }

        return ['id' => (string) $data['id'], 'name' => (string) $data['name'], 'version' => (string) $data['version'], 'api' => (int) $data['api'], 'provider' => $provider];
    }

    public function namespace(string $id): string
    {
        return 'VirtHubAddons\\'.Str::studly($id).'\\';
    }

    private function load(string $dir, ?string $expectedId): void
    {
        $manifest = $this->manifest($dir);
        if ($expectedId !== null && $manifest['id'] !== $expectedId) {
            throw new AddonException("addon.json ma id {$manifest['id']}, oczekiwano {$expectedId}");
        }
        $prefix = $this->namespace($manifest['id']);
        $src = realpath($dir.'/src') ?: $dir.'/src';
        spl_autoload_register(function (string $class) use ($prefix, $src) {
            if (! str_starts_with($class, $prefix)) {
                return;
            }
            $file = $src.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
        if ($manifest['provider'] !== null) {
            app()->register($manifest['provider']);
        }
        $this->loaded[$manifest['id']] = $manifest['version'];
    }

    /** Rozpakowanie z odrzuceniem ścieżek wychodzących poza katalog (zip slip) i dowiązań. */
    private function extract(string $zipFile, string $target): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipFile) !== true) {
            throw new AddonException(__('Paczka addonu nie jest poprawnym archiwum zip.'));
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $zip->getExternalAttributesIndex($i, $os, $attr);
                $isLink = $os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000;
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('#(^|/)\.\.(/|$)#', $name) || $isLink) {
                    throw new AddonException(__('Paczka addonu zawiera niedozwoloną ścieżkę: :name', ['name' => $name]));
                }
            }
            File::ensureDirectoryExists($target);
            if (! $zip->extractTo($target)) {
                throw new AddonException(__('Nie udało się rozpakować paczki addonu.'));
            }
        } finally {
            $zip->close();
        }
    }

    private function assertId(string $id): void
    {
        if (! preg_match('/^[a-z][a-z0-9-]{1,39}$/', $id)) {
            throw new AddonException(__('Nieprawidłowy identyfikator addonu.'));
        }
    }

    private function save(array $installed): void
    {
        ksort($installed);
        Setting::put(['addons.installed' => json_encode($installed, JSON_UNESCAPED_UNICODE)]);
    }
}
