<?php

namespace Tests\Feature;

use App\Domain\Security\Cbor;
use App\Domain\Security\WebAuthn;
use App\Domain\Security\WebAuthnException;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Klucze dostępu (WebAuthn) z programowym kluczem P-256, ponowne potwierdzenie
 * tożsamości, 2FA wymagane od klientów, przypięcie sesji i limit tokenów API.
 */
class PasskeysAndStepUpTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost';

    private \OpenSSLAsymmetricKey $key;

    private string $credentialId;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credentialId = random_bytes(32);
    }

    // --- programowy klucz FIDO2 ------------------------------------------------------

    private static function cbor(mixed $value): string
    {
        $head = function (int $major, int $len): string {
            if ($len < 24) {
                return chr(($major << 5) | $len);
            }
            if ($len < 256) {
                return chr(($major << 5) | 24).chr($len);
            }

            return chr(($major << 5) | 25).pack('n', $len);
        };
        if (is_int($value)) {
            return $value >= 0 ? $head(0, $value) : $head(1, -1 - $value);
        }
        if (is_string($value)) {
            return $head(mb_check_encoding($value, 'UTF-8') && ! preg_match('/[\x00-\x08]/', $value) && strlen($value) < 20 ? 3 : 2, strlen($value)).$value;
        }
        if (is_array($value) && array_is_list($value)) {
            return $head(4, count($value)).implode('', array_map([self::class, 'cbor'], $value));
        }
        $out = $head(5, count($value));
        foreach ($value as $k => $v) {
            $out .= self::cbor($k).self::cbor($v);
        }

        return $out;
    }

    private static function bytes(string $raw): string
    {
        // Ciąg bajtów (typ 2) niezależnie od zawartości.
        return chr((2 << 5) | 25).pack('n', strlen($raw)).$raw;
    }

    private function coseKey(): string
    {
        $d = openssl_pkey_get_details($this->key)['ec'];

        // Mapa COSE: 1:2 (EC2), 3:-7 (ES256), -1:1 (P-256), -2:x, -3:y
        return chr(0xA5).self::cbor(1).self::cbor(2).self::cbor(3).self::cbor(-7).self::cbor(-1).self::cbor(1)
            .self::cbor(-2).self::bytes($d['x']).self::cbor(-3).self::bytes($d['y']);
    }

    private function authData(bool $attested, int $flags = 0x05): string
    {
        $data = hash('sha256', 'localhost', true).chr($flags | ($attested ? 0x40 : 0)).pack('N', $this->counter);
        if ($attested) {
            $data .= str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$this->coseKey();
        }

        return $data;
    }

    private function clientData(string $type, string $challenge, string $origin = self::ORIGIN): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin]);
    }

    private function attestation(string $challenge, string $origin = self::ORIGIN): array
    {
        $att = chr(0xA3).self::cbor('fmt').self::cbor('none').self::cbor('attStmt').chr(0xA0).self::cbor('authData').self::bytes($this->authData(true));

        return [
            'name' => 'YubiKey',
            'id' => WebAuthn::b64u($this->credentialId),
            'clientDataJSON' => WebAuthn::b64u($this->clientData('webauthn.create', $challenge, $origin)),
            'attestationObject' => WebAuthn::b64u($att),
            'transports' => ['usb', 'nonsense'],
        ];
    }

    private function assertion(string $challenge, ?User $user = null, int $flags = 0x05): array
    {
        $this->counter++;
        $authData = $this->authData(false, $flags);
        $clientData = $this->clientData('webauthn.get', $challenge);
        openssl_sign($authData.hash('sha256', $clientData, true), $sig, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => WebAuthn::b64u($this->credentialId),
            'clientDataJSON' => WebAuthn::b64u($clientData),
            'authenticatorData' => WebAuthn::b64u($authData),
            'signature' => WebAuthn::b64u($sig),
        ];
    }

    private function registerPasskey(User $user): WebauthnCredential
    {
        $options = $this->actingAs($user)->postJson(route('panel.account.security.passkeys.options'))->assertOk()->json();
        $this->assertSame('localhost', $options['rp']['id']);
        $this->assertSame([-7, -8, -257], array_column($options['pubKeyCredParams'], 'alg'));
        $this->postJson(route('panel.account.security.passkeys.store'), $this->attestation($options['challenge']))
            ->assertOk()->assertJsonPath('redirect', route('panel.account.security'));

        return WebauthnCredential::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    // --- testy --------------------------------------------------------------------------

    public function test_rejestracja_i_logowanie_kluczem_bez_hasla(): void
    {
        $user = User::factory()->create();
        $credential = $this->registerPasskey($user);
        $this->assertSame('YubiKey', $credential->name);
        $this->assertSame('usb', $credential->transports);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $credential->public_key);

        // Ten sam klucz drugi raz — odmowa.
        $options = $this->postJson(route('panel.account.security.passkeys.options'))->json();
        $this->assertCount(1, $options['excludeCredentials']);
        $this->postJson(route('panel.account.security.passkeys.store'), $this->attestation($options['challenge']))->assertStatus(422);

        // Logowanie bez hasła (wymaga weryfikacji użytkownika — flaga UV).
        auth()->logout();
        $options = $this->postJson(route('login.passkey.options'))->assertOk()->json();
        $this->assertArrayNotHasKey('allowCredentials', $options);
        $this->postJson(route('login.passkey'), $this->assertion($options['challenge'], flags: 0x01))->assertStatus(422);
        $this->assertGuest();

        $options = $this->postJson(route('login.passkey.options'))->json();
        $this->postJson(route('login.passkey'), $this->assertion($options['challenge']))->assertOk()->assertJsonPath('redirect', route('panel.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($credential->fresh()->last_used_at);
        $this->assertSame($this->counter, $credential->fresh()->sign_count);
    }

    public function test_odrzuca_zle_wyzwanie_pochodzenie_podpis_i_cofniety_licznik(): void
    {
        $user = User::factory()->create();
        $this->registerPasskey($user);
        auth()->logout();

        // Inne pochodzenie przy rejestracji.
        $options = $this->actingAs($user)->postJson(route('panel.account.security.passkeys.options'))->json();
        $this->credentialId = random_bytes(16);
        $this->postJson(route('panel.account.security.passkeys.store'), $this->attestation($options['challenge'], 'https://evil.example'))
            ->assertStatus(422)->assertJsonFragment(['message' => 'Klucz odpowiedział dla innej strony (https://evil.example).']);
        auth()->logout();
        $this->credentialId = WebAuthn::unb64u(WebauthnCredential::firstOrFail()->credential_id);

        // Złe wyzwanie.
        $this->postJson(route('login.passkey.options'));
        $this->postJson(route('login.passkey'), $this->assertion('zle-wyzwanie'))->assertStatus(422);

        // Podpis innym kluczem.
        $options = $this->postJson(route('login.passkey.options'))->json();
        $good = $this->key;
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->postJson(route('login.passkey'), $this->assertion($options['challenge']))->assertStatus(422);
        $this->key = $good;

        // Licznik nie rośnie — sklonowany klucz.
        $options = $this->postJson(route('login.passkey.options'))->json();
        $this->postJson(route('login.passkey'), $this->assertion($options['challenge']))->assertOk();
        auth()->logout();
        $this->counter = 0;
        $options = $this->postJson(route('login.passkey.options'))->json();
        $this->postJson(route('login.passkey'), $this->assertion($options['challenge']))->assertStatus(422);
        $this->assertGuest();
    }

    public function test_klucz_jako_drugi_skladnik_po_hasle(): void
    {
        $user = User::factory()->create(['password' => 'tajne-haslo-123']);
        $this->registerPasskey($user);
        auth()->logout();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'tajne-haslo-123'])->assertRedirect(route('login.two-factor'));
        $this->assertGuest();
        $this->get(route('login.two-factor'))->assertOk()->assertSee('Użyj klucza dostępu')->assertDontSee('name="code"', false);
        $options = $this->postJson(route('login.two-factor.passkey.options'))->assertOk()->json();
        $this->assertCount(1, $options['allowCredentials']);
        // Drugi składnik: obecność wystarczy (bez UV).
        $this->postJson(route('login.two-factor.passkey'), $this->assertion($options['challenge'], flags: 0x01))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_step_up_przed_operacjami_wrazliwymi(): void
    {
        Queue::fake();
        $user = User::factory()->create(['password' => 'tajne-haslo-123']);
        $server = Server::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user)->withSession(['auth.confirmed_at' => now()->subMinutes(30)->getTimestamp()]);

        $this->from(route('panel.servers.show', $server))->delete(route('panel.servers.destroy', $server))->assertRedirect(route('confirm'));
        $this->assertNotSoftDeleted($server);
        $this->postJson(route('panel.account.security.tokens.store'), ['name' => 'x', 'scopes' => ['servers:read']])->assertStatus(423)->assertJsonPath('confirm_url', route('confirm'));

        $this->get(route('confirm'))->assertOk()->assertSee('Potwierdź tożsamość');
        $this->post(route('confirm'), ['password' => 'zle'])->assertSessionHasErrors('password');
        $this->post(route('confirm'), ['password' => 'tajne-haslo-123'])->assertRedirect(route('panel.servers.show', $server));

        $this->delete(route('panel.servers.destroy', $server))->assertRedirect();
        $this->assertNotSame(route('confirm'), $this->delete(route('panel.servers.destroy', $server))->headers->get('Location'));
    }

    public function test_step_up_kluczem_dostepu(): void
    {
        $user = User::factory()->create();
        $this->registerPasskey($user);
        $this->withSession(['auth.confirmed_at' => 0, 'confirm.return' => route('panel.account.security')]);
        $options = $this->postJson(route('confirm.passkey.options'))->assertOk()->json();
        $this->postJson(route('confirm.passkey'), $this->assertion($options['challenge']))->assertOk()->assertJsonPath('redirect', route('panel.account.security'));
        $this->postJson(route('panel.account.security.passkeys.options'))->assertOk();
    }

    public function test_klienci_musza_miec_2fa_gdy_wymagane(): void
    {
        $customer = User::factory()->create();
        Setting::put(['security.customer_2fa' => '1']);

        $this->actingAs($customer)->get(route('panel.dashboard'))->assertRedirect(route('panel.account.security'));
        $this->get(route('panel.account.security'))->assertOk()->assertSee('dodaj kod z aplikacji albo klucz dostępu');
        Sanctum::actingAs($customer, ['servers:read']);
        $this->withToken('x')->getJson('/api/v1/servers')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $credential = $this->registerPasskey($customer);
        $this->actingAs($customer)->get(route('panel.dashboard'))->assertOk();

        // Jedyny drugi składnik przy wymaganym 2FA nie zniknie.
        $this->delete(route('panel.account.security.passkeys.destroy', $credential))->assertSessionHasErrors('passkeys');
        $this->assertModelExists($credential);
    }

    public function test_przypiecie_sesji_do_ip_i_przegladarki(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put(route('panel.account.security.session-lock'), ['session_lock' => 1])->assertRedirect();
        $this->assertTrue($user->fresh()->session_lock);
        $this->get(route('panel.dashboard'))->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get(route('panel.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_limit_zapytan_tokenu_api(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('skrypt', ['servers:read']);
        $token->accessToken->forceFill(['rate_limit' => 2])->save();
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];

        $this->getJson('/api/v1/servers', $headers)->assertOk()->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '1');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/servers', $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/servers', $headers)->assertStatus(429)->assertHeader('Retry-After');

        // Formularz tokenu zapisuje limit.
        $this->actingAs($user)->post(route('panel.account.security.tokens.store'), ['name' => 'drugi', 'scopes' => ['servers:read'], 'rate_limit' => 30])->assertSessionHasNoErrors();
        $this->assertSame(30, (int) $user->tokens()->where('name', 'drugi')->value('rate_limit'));
        $this->get(route('panel.account.security'))->assertSee('30 / min');
    }

    public function test_cbor_i_klucze_rsa_ed25519(): void
    {
        $this->assertSame([1 => 2, -1 => 'ab', 'x' => [true, null, -300]], Cbor::decode(hex2bin('a3').self::cbor(1).self::cbor(2).self::cbor(-1).self::bytes('ab').self::cbor('x').hex2bin('83f5f6').self::cbor(-300)));
        $this->expectExceptionOnInvalid(fn () => Cbor::decode(hex2bin('5a7fffffff')));

        $webauthn = app(WebAuthn::class);
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $d = openssl_pkey_get_details($rsa)['rsa'];
        $pem = $webauthn->coseToPem([1 => 3, 3 => -257, -1 => $d['n'], -2 => $d['e']]);
        openssl_sign('dane', $sig, $rsa, OPENSSL_ALGO_SHA256);
        $this->assertTrue($webauthn->verifySignature($pem, 'dane', $sig));
        $this->assertFalse($webauthn->verifySignature($pem, 'inne', $sig));

        $pair = sodium_crypto_sign_keypair();
        $ed = $webauthn->coseToPem([1 => 1, 3 => -8, -1 => 6, -2 => sodium_crypto_sign_publickey($pair)]);
        $this->assertTrue($webauthn->verifySignature($ed, 'dane', sodium_crypto_sign_detached('dane', sodium_crypto_sign_secretkey($pair))));

        $this->expectExceptionOnInvalid(fn () => $webauthn->coseToPem([1 => 2, 3 => -35]));
    }

    private function expectExceptionOnInvalid(\Closure $fn): void
    {
        try {
            $fn();
            $this->fail('Oczekiwano WebAuthnException');
        } catch (WebAuthnException) {
            $this->addToAssertionCount(1);
        }
    }
}
