<?php

namespace Tests\Feature;

use App\Domain\Licensing\LicenseManager;
use App\Domain\Updates\Semver;
use App\Domain\Updates\Updates;
use App\Models\Hypervisor;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    private string $dir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->dir = sys_get_temp_dir().'/vh-portal-updates-'.uniqid();
        mkdir($this->dir);
        touch($this->dir.'/unit.path');
        config([
            'app.url' => 'https://panel.example.com',
            'virthub.license.server' => 'https://portal.test',
            'virthub.license.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'virthub.update_dir' => $this->dir,
            'virthub.update_unit' => $this->dir.'/unit.path',
        ]);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function license(array $payload = []): void
    {
        $token = rtrim(strtr(base64_encode(json_encode($payload + [
            'domain' => 'panel.example.com', 'status' => 'active', 'type' => 'paid', 'nodes' => 2, 'channel' => 'stable',
            'expires_at' => null, 'issued_at' => now()->getTimestamp(), 'addons' => [],
        ])), '+/', '-_'), '=');
        Setting::put(['license.key' => 'VH-AAAA-BBBB-CCCC-DDDD', 'license.token' => $token, 'license.signature' => base64_encode(sodium_crypto_sign_detached($token, $this->secret))]);
        $this->app->forgetInstance(LicenseManager::class); // stan licencji jest zapamiętywany na czas żądania
    }

    private function release(string $version): array
    {
        return ['version' => $version, 'channel' => 'stable', 'notes' => "Zmiany {$version}", 'security' => false, 'published_at' => '2026-10-15T10:00:00+00:00',
            'sha256' => str_repeat('a', 64), 'signature' => base64_encode(str_repeat('s', 64)), 'size' => 1000, 'bump' => 'minor',
            'download_url' => "https://portal.test/api/v1/updates/download/{$version}?signature=x"];
    }

    private function fakePortal(?array $latest, array $releases = [], bool $security = false): void
    {
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($latest, $releases, $security) {
            if (str_contains($request->url(), 'portal.test/api/v1/updates/check')) {
                if (! empty($request['target'])) {
                    return Http::response(['update_available' => false, 'latest' => $this->release($request['target']), 'releases' => []]);
                }

                return Http::response(['current' => $request['current'], 'channel' => 'stable', 'update_available' => $latest !== null,
                    'latest' => $latest, 'releases' => $releases, 'security' => $security]);
            }

            return Http::response(['state' => 'queued'], 202); // agent węzła
        });
    }

    public function test_wersja_semver_z_pliku_i_porownanie(): void
    {
        $version = trim((string) file_get_contents(base_path('../VERSION')));
        $this->assertTrue(Semver::valid($version), 'VERSION w repozytorium to poprawny semver');
        $this->assertSame($version, app(Updates::class)->version());
        $this->assertLessThan(0, Semver::compare('1.0.0-rc.1', '1.0.0'));
        $this->assertLessThan(0, Semver::compare('1.9.0', '1.10.0'));
        $this->assertSame('major', Semver::bump('1.4.2', '2.0.0'));
        $this->assertSame('1.0.0-beta.1', Updates::short('1.0.0-beta.1'));
        $this->assertSame('abcdef1', Updates::short(str_repeat('abcdef1', 5).'abcde'));
    }

    public function test_bez_licencji_tryb_repozytorium(): void
    {
        $updates = app(Updates::class);
        $this->assertFalse($updates->viaPortal());
        $this->assertFalse($updates->check()['ok']);
    }

    public function test_aktualizacja_panelu_z_portalu(): void
    {
        $this->license();
        $current = app(Updates::class)->version();
        $new = $this->release('9.0.0');
        $this->fakePortal($new, [$new, $this->release('8.5.0')], security: true);

        $this->actingAs($this->admin)->get(route('panel.admin.updates'))->assertOk()
            ->assertSee('9.0.0')->assertSee('8.5.0')->assertSee(__('poprawki bezpieczeństwa'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/updates/check') && $r['current'] === $current && $r['key'] === 'VH-AAAA-BBBB-CCCC-DDDD');

        $this->actingAs($this->admin)->post(route('panel.admin.updates.panel'))->assertSessionHasNoErrors();
        $release = json_decode((string) file_get_contents($this->dir.'/release.json'), true);
        $this->assertSame(['version' => '9.0.0', 'url' => $new['download_url'], 'sha256' => $new['sha256'], 'signature' => $new['signature']], $release);
        $this->assertSame('9.0.0', json_decode((string) file_get_contents($this->dir.'/request'), true)['target']);
    }

    public function test_najnowsza_wersja_bez_zlecenia(): void
    {
        $this->license();
        $this->fakePortal(null);
        $this->actingAs($this->admin)->get(route('panel.admin.updates'))->assertOk()->assertSee(__('Panel ma najnowszą wersję :version.', ['version' => app(Updates::class)->version()]));
        $this->actingAs($this->admin)->post(route('panel.admin.updates.panel'))->assertSessionHasErrors('update');
        $this->assertFileDoesNotExist($this->dir.'/request');
    }

    public function test_wezel_dostaje_wydanie_wersji_panelu(): void
    {
        $this->license();
        $this->fakePortal(null);
        $node = Hypervisor::factory()->create(['enrolled_at' => now(), 'last_health' => ['build' => 'abc', 'remote_update' => true]]);
        $version = app(Updates::class)->version();

        $this->actingAs($this->admin)->post(route('panel.admin.updates.node', $node))->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/updates/check') && $r['target'] === $version);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/system/update') && $r['release']['version'] === $version
            && $r['release']['sha256'] === str_repeat('a', 64) && str_starts_with($r['release']['url'], 'https://portal.test/'));

        // Węzeł z wersją panelu jest aktualny — „wszystkie” go pomija.
        $node->forceFill(['last_health' => ['build' => $version, 'remote_update' => true]])->save();
        Http::swap(new Factory);
        Http::fake();
        $this->actingAs($this->admin)->post(route('panel.admin.updates.nodes'))->assertSessionHas('status');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/system/update'));
    }

    public function test_limit_wezlow_z_licencji(): void
    {
        $license = app(LicenseManager::class);

        // Bez serwera licencji (instalacja deweloperska) — bez ograniczeń.
        config(['virthub.license.server' => '']);
        $this->assertNull($license->canAddNode());

        config(['virthub.license.server' => 'https://portal.test']);
        $this->assertNotNull(app(LicenseManager::class)->canAddNode(), 'skonfigurowany portal bez licencji — nie dodamy węzła');

        $this->license(['nodes' => 2]);
        Hypervisor::factory()->create();
        $this->assertNull(app(LicenseManager::class)->canAddNode());
        $this->actingAs($this->admin)->post(route('panel.admin.hypervisors.store'), ['name' => 'drugi'])->assertSessionHasNoErrors();
        $this->assertSame(2, Hypervisor::count());
        $this->actingAs($this->admin)->post(route('panel.admin.hypervisors.store'), ['name' => 'trzeci'])->assertSessionHasErrors('name');
        $this->assertSame(2, Hypervisor::count());
        $this->assertSame(2, app(LicenseManager::class)->nodeLimit());
        $this->assertSame('paid', app(LicenseManager::class)->type());
    }

    public function test_weryfikacja_licencji_zglasza_liczbe_wezlow(): void
    {
        Hypervisor::factory()->count(3)->create(['enrolled_at' => now()]);
        Hypervisor::factory()->create(['enrolled_at' => null]);
        Http::swap(new Factory);
        Http::fake(['portal.test/*' => Http::response(['message' => 'x'], 404)]);
        Setting::put(['license.key' => 'VH-AAAA-BBBB-CCCC-DDDD']);
        app(LicenseManager::class)->refresh();
        Http::assertSent(fn (Request $r) => $r['nodes'] === 3 && $r['panel_version'] === app(Updates::class)->version());
    }
}
