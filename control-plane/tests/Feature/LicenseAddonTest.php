<?php

namespace Tests\Feature;

use App\Domain\External\ProviderRegistry;
use App\Domain\Licensing\AddonException;
use App\Domain\Licensing\AddonManager;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Licensing\Signature;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/** Licencja (podpisany token) i addony (podpisane paczki) — bez prawdziwego serwera licencji. */
class LicenseAddonTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    private string $addonsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->addonsPath = sys_get_temp_dir().'/vh-addons-'.uniqid();
        config([
            'app.url' => 'https://panel.example.com',
            'virthub.license.server' => 'https://license.test',
            'virthub.license.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'virthub.addons_path' => $this->addonsPath,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->addonsPath);
        parent::tearDown();
    }

    /** Odpowiedź serwera licencji z tokenem podpisanym kluczem testowym. */
    private function token(array $payload, ?string $secret = null): array
    {
        $token = rtrim(strtr(base64_encode(json_encode($payload + [
            'domain' => 'panel.example.com', 'status' => 'active', 'expires_at' => null, 'issued_at' => now()->getTimestamp(), 'addons' => [],
        ])), '+/', '-_'), '=');

        return ['token' => $token, 'signature' => base64_encode(sodium_crypto_sign_detached($token, $secret ?? $this->secret))];
    }

    private function fakeServer(array $verify, array $extra = []): void
    {
        Http::swap(new Factory);
        Http::fake($extra + ['license.test/api/v1/license/verify' => Http::response($verify)]);
    }

    public function test_aktywacja_licencji_i_stany_tokenu(): void
    {
        $license = app(LicenseManager::class);
        $this->assertSame(LicenseManager::STATE_NONE, $license->status()['state']);

        $this->fakeServer($this->token(['addons' => ['onidel' => ['name' => 'Onidel', 'version' => '1.0.0']], 'licensee' => 'Steerio']));
        $status = $license->activate('VH-AAAA-BBBB-CCCC-DDDD');
        $this->assertSame(LicenseManager::STATE_VALID, $status['state']);
        $this->assertTrue($license->hasAddon('onidel'));
        $this->assertFalse($license->hasAddon('hetzner'));
        Http::assertSent(fn ($r) => $r['key'] === 'VH-AAAA-BBBB-CCCC-DDDD' && $r['domain'] === 'panel.example.com');

        // Podpis obcym kluczem → nie przyjęty, poprzedni token zostaje.
        $this->fakeServer($this->token(['addons' => ['hetzner' => []]], sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        $status = $license->refresh();
        $this->assertNotNull($status['error']);
        $this->assertTrue($license->hasAddon('onidel'));
        $this->assertFalse($license->hasAddon('hetzner'));

        // Inna domena, wygaśnięcie, zawieszenie.
        foreach ([[['domain' => 'evil.example.com'], LicenseManager::STATE_DOMAIN], [['expires_at' => now()->subDay()->toIso8601String()], LicenseManager::STATE_EXPIRED],
            [['status' => 'suspended'], LicenseManager::STATE_SUSPENDED]] as [$payload, $state]) {
            $this->fakeServer($this->token($payload + ['addons' => ['onidel' => []]]));
            $this->assertSame($state, $license->refresh()['state']);
            $this->assertFalse($license->hasAddon('onidel'));
        }

        // Brak kontaktu: token ważny przez okres łaski, potem nie.
        $this->fakeServer($this->token(['addons' => ['onidel' => []]]));
        $license->refresh();
        Http::swap(new Factory);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));
        $this->travel(6)->days();
        $this->assertSame(LicenseManager::STATE_VALID, $license->refresh()['state']);
        $this->travel(2)->days();
        $this->assertSame(LicenseManager::STATE_STALE, $license->refresh()['state']);

        // Serwer licencji: klucz nieważny → token skasowany.
        $this->fakeServer(['message' => 'Licencja nie istnieje.']);
        Http::swap(new Factory);
        Http::fake(['license.test/*' => Http::response(['message' => 'Licencja nie istnieje.'], 404)]);
        $status = $license->refresh();
        $this->assertSame(LicenseManager::STATE_INVALID, $status['state']);
        $this->assertSame('Licencja nie istnieje.', $status['error']);
    }

    /** Paczka addonu z działającym ServiceProviderem rejestrującym sterownik dostawcy. */
    private function package(string $id = 'demo', string $version = '1.0.0', array $extraFiles = []): string
    {
        $file = tempnam(sys_get_temp_dir(), 'addon');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $zip->addFromString('addon.json', json_encode(['id' => $id, 'name' => 'Demo', 'version' => $version, 'api' => 1, 'provider' => 'VirtHubAddons\\Demo\\AddonServiceProvider']));
        $zip->addFromString('src/AddonServiceProvider.php', <<<'PHP'
            <?php
            namespace VirtHubAddons\Demo;
            class AddonServiceProvider extends \Illuminate\Support\ServiceProvider
            {
                public function boot(): void
                {
                    app(\App\Domain\External\ProviderRegistry::class)->register('demo', 'Demo Cloud', DemoDriver::class, 'demo');
                }
            }
            PHP);
        $zip->addFromString('src/DemoDriver.php', '<?php namespace VirtHubAddons\Demo; class DemoDriver extends \Tests\Feature\FakeProviderDriver {}');
        foreach ($extraFiles as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $data = file_get_contents($file);
        unlink($file);

        return $data;
    }

    private function sign(string $id, string $version, string $package): array
    {
        $sha = hash('sha256', $package);

        return [$sha, base64_encode(sodium_crypto_sign_detached(Signature::packageMessage($id, $version, $sha), $this->secret))];
    }

    public function test_instalacja_podpisanej_paczki_i_ladowanie_kodu(): void
    {
        $this->fakeServer($this->token(['addons' => ['demo' => ['name' => 'Demo', 'version' => '1.0.0']]]));
        app(LicenseManager::class)->activate('VH-KEY');
        $addons = app(AddonManager::class);

        $package = $this->package();
        [$sha, $sig] = $this->sign('demo', '1.0.0', $package);

        // Zmieniona zawartość, cudzy podpis, zła wersja — odrzucone.
        $this->assertThrows(fn () => $addons->installPackage('demo', '1.0.0', $package.'x', $sha, $sig), AddonException::class);
        $this->assertThrows(fn () => $addons->installPackage('demo', '1.0.1', $package, $sha, $sig), AddonException::class);
        [$sha2, $sig2] = $this->sign('other', '1.0.0', $package);
        $this->assertThrows(fn () => $addons->installPackage('demo', '1.0.0', $package, $sha2, $sig2), AddonException::class);
        $this->assertDirectoryDoesNotExist($this->addonsPath.'/demo');

        // Pobranie z serwera licencji.
        Http::fake(['license.test/api/v1/addons/demo/download' => Http::response(['version' => '1.0.0', 'sha256' => $sha, 'signature' => $sig, 'package' => base64_encode($package)])]);
        $this->assertSame('1.0.0', $addons->install('demo', User::factory()->create(['role' => User::ROLE_ADMIN])));
        $this->assertFileExists($this->addonsPath.'/demo/1.0.0/src/DemoDriver.php');

        // Start aplikacji: addon ładuje się i rejestruje sterownik.
        $fresh = new AddonManager(app(LicenseManager::class));
        $fresh->boot();
        $this->assertSame(['demo' => '1.0.0'], $fresh->loaded());
        $this->assertTrue(app(ProviderRegistry::class)->has('demo'));

        // Bez licencji na addon — nie ładuje się.
        Setting::put(['license.token' => null]);
        $off = new AddonManager(new LicenseManager);
        $off->boot();
        $this->assertSame([], $off->loaded());
    }

    public function test_paczka_ze_sciezka_poza_katalogiem_jest_odrzucona(): void
    {
        $package = $this->package(extraFiles: ['../../evil.php' => '<?php echo 1;']);
        [$sha, $sig] = $this->sign('demo', '1.0.0', $package);

        $this->assertThrows(fn () => app(AddonManager::class)->installPackage('demo', '1.0.0', $package, $sha, $sig), AddonException::class);
        $this->assertFileDoesNotExist(dirname($this->addonsPath).'/evil.php');
    }

    public function test_strona_licencji_tylko_dla_administratora(): void
    {
        $this->fakeServer($this->token([]), ['license.test/api/v1/addons' => Http::response(['addons' => [['id' => 'onidel', 'name' => 'Onidel', 'owned' => false, 'version' => '1.0.0']]])]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs(User::factory()->create())->get(route('panel.admin.license'))->assertForbidden();

        $this->actingAs($admin)->post(route('panel.admin.license.activate'), ['key' => 'VH-AAAA-BBBB'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->get(route('panel.admin.license'))->assertOk()
            ->assertSee(__('aktywna'))->assertSee('Onidel')->assertSee(__('niewykupiony'))->assertDontSee('VH-AAAA-BBBB');
    }
}
