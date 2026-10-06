<?php

namespace Tests\Feature;

use App\Licensing\AddonPublisher;
use App\Models\Addon;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class LicenseApiTest extends TestCase
{
    use RefreshDatabase;

    private string $public;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $pair = sodium_crypto_sign_keypair();
        config(['licensing.signing_key' => base64_encode(sodium_crypto_sign_secretkey($pair))]);
        $this->public = sodium_crypto_sign_publickey($pair);
    }

    private function decode(array $response): array
    {
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($response['signature']), $response['token'], $this->public), 'podpis tokenu');

        return json_decode(base64_decode(strtr($response['token'], '-_', '+/')), true);
    }

    private function zip(string $id, string $version): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pkg').'.zip';
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE);
        $zip->addFromString('addon.json', json_encode(['id' => $id, 'name' => 'Onidel', 'version' => $version, 'api' => 1]));
        $zip->addFromString('src/Driver.php', '<?php // driver');
        $zip->close();

        return $file;
    }

    public function test_weryfikacja_przypina_domene_i_zwraca_podpisany_token(): void
    {
        $license = License::query()->create(['key' => License::generateKey(), 'owner_name' => 'Steerio']);
        $addon = Addon::query()->create(['slug' => 'onidel', 'name' => 'Onidel']);
        app(AddonPublisher::class)->publish($addon, $this->zip('onidel', '1.0.0'));
        $license->addons()->attach($addon->id);

        $this->postJson('/api/v1/license/verify', ['key' => 'VH-NOPE', 'domain' => 'a.example.com'])->assertNotFound();

        $token = $this->decode($this->postJson('/api/v1/license/verify', ['key' => strtolower($license->key), 'domain' => 'Panel.Example.com', 'panel_version' => 'abc123'])
            ->assertOk()->json());
        $this->assertSame('panel.example.com', $token['domain']);
        $this->assertSame('active', $token['status']);
        $this->assertSame(['onidel' => ['name' => 'Onidel', 'version' => '1.0.0']], $token['addons']);
        $this->assertSame('panel.example.com', $license->fresh()->domain);
        $this->assertSame('abc123', $license->fresh()->panel_version);

        // Inna domena — odmowa.
        $this->postJson('/api/v1/license/verify', ['key' => $license->key, 'domain' => 'evil.example.com'])->assertForbidden();

        // Zawieszona: token ze stanem, bez addonów; pobranie zablokowane.
        $license->update(['status' => License::SUSPENDED]);
        $token = $this->decode($this->postJson('/api/v1/license/verify', ['key' => $license->key, 'domain' => 'panel.example.com'])->json());
        $this->assertSame('suspended', $token['status']);
        $this->assertSame([], $token['addons']);
        $this->postJson('/api/v1/addons/onidel/download', ['key' => $license->key, 'domain' => 'panel.example.com'])->assertForbidden();
    }

    public function test_pobranie_paczki_podpisanej_w_formacie_panelu(): void
    {
        $license = License::query()->create(['key' => License::generateKey(), 'owner_name' => 'Steerio', 'domain' => 'panel.example.com']);
        $onidel = Addon::query()->create(['slug' => 'onidel', 'name' => 'Onidel']);
        Addon::query()->create(['slug' => 'hetzner', 'name' => 'Hetzner', 'price' => '49 zł']);
        Addon::query()->create(['slug' => 'secret', 'name' => 'Ukryty', 'is_public' => false]);
        $publisher = app(AddonPublisher::class);
        $publisher->publish($onidel, $this->zip('onidel', '1.0.0'));
        $publisher->publish($onidel, $this->zip('onidel', '1.2.0'));
        $license->addons()->attach($onidel->id);

        $catalog = $this->postJson('/api/v1/addons', ['key' => $license->key, 'domain' => 'panel.example.com'])->assertOk()->json('addons');
        $this->assertSame(['hetzner' => false, 'onidel' => true], collect($catalog)->pluck('owned', 'id')->all());

        $this->postJson('/api/v1/addons/hetzner/download', ['key' => $license->key, 'domain' => 'panel.example.com'])->assertForbidden();
        $r = $this->postJson('/api/v1/addons/onidel/download', ['key' => $license->key, 'domain' => 'panel.example.com'])->assertOk()->json();
        $package = base64_decode($r['package']);
        $this->assertSame('1.2.0', $r['version']);
        $this->assertSame(hash('sha256', $package), $r['sha256']);
        // Ta sama wiadomość co App\Domain\Licensing\Signature::packageMessage w panelu.
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($r['signature']), "virthub-addon\nonidel\n1.2.0\n".$r['sha256'], $this->public));

        // Wycofanie wersji → panel dostaje poprzednią; ta sama wersja drugi raz — odrzucona.
        $onidel->versions()->where('version', '1.2.0')->update(['is_published' => false]);
        $this->assertSame('1.0.0', $this->postJson('/api/v1/addons/onidel/download', ['key' => $license->key, 'domain' => 'panel.example.com'])->json('version'));
        $this->expectException(\RuntimeException::class);
        $publisher->publish($onidel, $this->zip('onidel', '1.0.0'));
    }

    public function test_panel_administratora(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->get('/licenses')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/licenses')->assertForbidden();

        $this->actingAs($admin)->post('/addons', ['slug' => 'onidel', 'name' => 'Onidel', 'is_public' => 1])->assertSessionHasNoErrors();
        $addon = Addon::query()->sole();
        $file = new UploadedFile($this->zip('onidel', '1.0.0'), 'onidel.zip', 'application/zip', null, true);
        $this->actingAs($admin)->post("/addons/{$addon->id}/versions", ['package' => $file])->assertSessionHasNoErrors();
        $bad = new UploadedFile($this->zip('other', '1.0.0'), 'other.zip', 'application/zip', null, true);
        $this->actingAs($admin)->post("/addons/{$addon->id}/versions", ['package' => $bad])->assertSessionHasErrors('package');

        $this->actingAs($admin)->post('/licenses', ['owner_name' => 'Klient', 'status' => 'active', 'addons' => [$addon->id]])->assertSessionHasNoErrors();
        $license = License::query()->sole();
        $this->assertMatchesRegularExpression('/^VH-[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/', $license->key);
        $this->assertSame(['onidel'], $license->addons->pluck('slug')->all());
        $this->actingAs($admin)->get('/licenses')->assertOk()->assertSee($license->key);
        $this->actingAs($admin)->get("/licenses/{$license->id}/edit")->assertOk();
        $this->actingAs($admin)->get('/addons')->assertOk()->assertSee('1.0.0');
    }
}
