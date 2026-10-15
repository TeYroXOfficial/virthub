<?php

namespace App\Domain\Security;

use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * WebAuthn (passkeys, klucze FIDO2) bez zewnętrznych bibliotek.
 *
 * Rejestracja prosi o atestację „none” — panel nie sprawdza producenta
 * klucza, tylko zapisuje jego klucz publiczny (COSE → PEM). Logowanie
 * sprawdza podpis nad authenticatorData ‖ SHA-256(clientDataJSON), wyzwanie,
 * pochodzenie strony, identyfikator RP i licznik podpisów (wykrywa sklonowane
 * klucze). Obsługiwane algorytmy: ES256, RS256, EdDSA (Ed25519).
 */
class WebAuthn
{
    private const SESSION = 'webauthn.challenge';

    private const TIMEOUT_MS = 120000;

    public const ALGORITHMS = [-7, -8, -257];

    // --- opcje dla przeglądarki ------------------------------------------------

    /** Opcje navigator.credentials.create() dla nowego klucza użytkownika. */
    public function creationOptions(Request $request, User $user): array
    {
        return [
            'challenge' => $this->challenge($request, 'create'),
            'rp' => ['name' => (string) config('virthub.brand', 'VirtHub'), 'id' => $this->rpId($request)],
            'user' => [
                'id' => self::b64u($this->userHandle($user)),
                'name' => $user->email,
                'displayName' => $user->name ?: $user->email,
            ],
            'pubKeyCredParams' => array_map(fn (int $alg) => ['type' => 'public-key', 'alg' => $alg], self::ALGORITHMS),
            'timeout' => self::TIMEOUT_MS,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
            'excludeCredentials' => $user->passkeys()->pluck('credential_id')->map(fn ($id) => ['type' => 'public-key', 'id' => $id])->values()->all(),
        ];
    }

    /** Opcje navigator.credentials.get(); bez użytkownika — logowanie bez hasła (klucz wykrywalny). */
    public function requestOptions(Request $request, ?User $user = null): array
    {
        return array_filter([
            'challenge' => $this->challenge($request, 'get'),
            'rpId' => $this->rpId($request),
            'timeout' => self::TIMEOUT_MS,
            'userVerification' => $user ? 'discouraged' : 'required',
            'allowCredentials' => $user ? $user->passkeys()->get(['credential_id', 'transports'])->map(fn ($c) => array_filter([
                'type' => 'public-key', 'id' => $c->credential_id,
                'transports' => $c->transports ? explode(',', $c->transports) : null,
            ]))->values()->all() : null,
        ], fn ($v) => $v !== null);
    }

    // --- weryfikacja -------------------------------------------------------------

    /**
     * Rejestracja: dane z navigator.credentials.create() → zapisany klucz.
     *
     * @param  array{id: string, clientDataJSON: string, attestationObject: string, transports?: list<string>}  $response
     */
    public function register(Request $request, User $user, array $response, string $name): WebauthnCredential
    {
        $this->clientData($request, $response['clientDataJSON'] ?? '', 'webauthn.create');
        $attestation = Cbor::decode(self::unb64u($response['attestationObject'] ?? ''));
        if (! is_array($attestation) || ! isset($attestation['authData']) || ! is_string($attestation['authData'])) {
            throw new WebAuthnException(__('Nieprawidłowa odpowiedź klucza (attestationObject).'));
        }
        $auth = $this->authData($request, $attestation['authData']);
        if (($auth['flags'] & 0x40) === 0 || $auth['credentialId'] === null) {
            throw new WebAuthnException(__('Klucz nie przekazał danych uwierzytelniających.'));
        }
        $credentialId = self::b64u($auth['credentialId']);
        if (WebauthnCredential::where('credential_id', $credentialId)->exists()) {
            throw new WebAuthnException(__('Ten klucz jest już zarejestrowany.'));
        }

        $transports = array_values(array_intersect((array) ($response['transports'] ?? []), ['usb', 'nfc', 'ble', 'internal', 'hybrid', 'smart-card']));

        return $user->passkeys()->create([
            'name' => mb_substr(trim($name) ?: __('Klucz dostępu'), 0, 80),
            'credential_id' => $credentialId,
            'public_key' => $this->coseToPem($auth['publicKey']),
            'sign_count' => $auth['signCount'],
            'transports' => $transports ? implode(',', $transports) : null,
        ]);
    }

    /**
     * Logowanie: dane z navigator.credentials.get() → klucz (i jego właściciel).
     *
     * @param  array{id: string, clientDataJSON: string, authenticatorData: string, signature: string, userHandle?: ?string}  $response
     */
    public function verify(Request $request, array $response, ?User $expectedUser = null, bool $requireUv = false): WebauthnCredential
    {
        $credential = WebauthnCredential::where('credential_id', (string) ($response['id'] ?? ''))->with('user')->first();
        if ($credential === null || ($expectedUser !== null && $credential->user_id !== $expectedUser->id)) {
            throw new WebAuthnException(__('Nieznany klucz dostępu.'));
        }
        $clientDataJson = self::unb64u($response['clientDataJSON'] ?? '');
        $this->clientData($request, $response['clientDataJSON'] ?? '', 'webauthn.get');
        $authData = self::unb64u($response['authenticatorData'] ?? '');
        $auth = $this->authData($request, $authData, requireUv: $requireUv);
        if (! empty($response['userHandle']) && ! hash_equals(self::b64u($this->userHandle($credential->user)), (string) $response['userHandle'])) {
            throw new WebAuthnException(__('Klucz należy do innego konta.'));
        }
        $signed = $authData.hash('sha256', $clientDataJson, true);
        if (! $this->verifySignature($credential->public_key, $signed, self::unb64u($response['signature'] ?? ''))) {
            throw new WebAuthnException(__('Podpis klucza się nie zgadza.'));
        }
        // Licznik podpisów: klucze, które go prowadzą, muszą go zwiększać — inaczej to klon.
        if ($auth['signCount'] > 0 || $credential->sign_count > 0) {
            if ($auth['signCount'] <= $credential->sign_count) {
                throw new WebAuthnException(__('Licznik klucza się cofnął — możliwy sklonowany klucz. Usuń go i zarejestruj ponownie.'));
            }
        }
        $credential->forceFill(['sign_count' => $auth['signCount'], 'last_used_at' => now()])->save();

        return $credential;
    }

    // --- szczegóły protokołu ----------------------------------------------------

    private function challenge(Request $request, string $kind): string
    {
        $challenge = self::b64u(random_bytes(32));
        $request->session()->put(self::SESSION, ['value' => $challenge, 'kind' => $kind, 'expires' => now()->addMilliseconds(self::TIMEOUT_MS)->getTimestamp()]);

        return $challenge;
    }

    /** @return array<string, mixed> */
    private function clientData(Request $request, string $encoded, string $type): array
    {
        $stored = $request->session()->pull(self::SESSION);
        $data = json_decode(self::unb64u($encoded), true);
        if (! is_array($data) || ($data['type'] ?? null) !== $type) {
            throw new WebAuthnException(__('Nieprawidłowa odpowiedź klucza (clientDataJSON).'));
        }
        if (! is_array($stored) || ($stored['expires'] ?? 0) < now()->getTimestamp()
            || ! hash_equals((string) $stored['value'], (string) ($data['challenge'] ?? ''))) {
            throw new WebAuthnException(__('Wyzwanie wygasło albo się nie zgadza — spróbuj jeszcze raz.'));
        }
        if (! in_array((string) ($data['origin'] ?? ''), $this->origins($request), true)) {
            throw new WebAuthnException(__('Klucz odpowiedział dla innej strony (:origin).', ['origin' => (string) ($data['origin'] ?? '?')]));
        }

        return $data;
    }

    /** @return array{flags: int, signCount: int, credentialId: ?string, publicKey: mixed} */
    private function authData(Request $request, string $raw, bool $requireUv = false): array
    {
        if (strlen($raw) < 37) {
            throw new WebAuthnException(__('Nieprawidłowe dane klucza (authenticatorData).'));
        }
        if (! hash_equals(hash('sha256', $this->rpId($request), true), substr($raw, 0, 32))) {
            throw new WebAuthnException(__('Klucz został zarejestrowany dla innej domeny.'));
        }
        $flags = ord($raw[32]);
        if (($flags & 0x01) === 0) {
            throw new WebAuthnException(__('Klucz nie potwierdził obecności użytkownika.'));
        }
        if ($requireUv && ($flags & 0x04) === 0) {
            throw new WebAuthnException(__('Klucz nie zweryfikował użytkownika (PIN albo biometria).'));
        }
        $signCount = unpack('N', substr($raw, 33, 4))[1];
        $credentialId = null;
        $publicKey = null;
        if ($flags & 0x40) {
            if (strlen($raw) < 55) {
                throw new WebAuthnException(__('Nieprawidłowe dane klucza (authenticatorData).'));
            }
            $length = unpack('n', substr($raw, 53, 2))[1];
            $credentialId = substr($raw, 55, $length);
            if (strlen($credentialId) !== $length) {
                throw new WebAuthnException(__('Nieprawidłowe dane klucza (authenticatorData).'));
            }
            $publicKey = Cbor::decode(substr($raw, 55 + $length), allowTrailing: true);
        }

        return ['flags' => $flags, 'signCount' => $signCount, 'credentialId' => $credentialId, 'publicKey' => $publicKey];
    }

    /** Klucz COSE → PEM (EC2/RSA) albo „ed25519:<base64>” (OKP). */
    public function coseToPem(mixed $cose): string
    {
        if (! is_array($cose)) {
            throw new WebAuthnException(__('Nieprawidłowy klucz publiczny.'));
        }
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === 2 && $alg === -7 && ($cose[-1] ?? null) === 1 && strlen((string) ($cose[-2] ?? '')) === 32 && strlen((string) ($cose[-3] ?? '')) === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').chr(4).$cose[-2].$cose[-3];

            return self::pem($der);
        }
        if ($kty === 3 && $alg === -257 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null)) {
            $rsa = Der::sequence(Der::integer($cose[-1]).Der::integer($cose[-2]));
            $der = Der::sequence(Der::sequence(hex2bin('06092a864886f70d0101010500')).Der::bitString($rsa));

            return self::pem($der);
        }
        if ($kty === 1 && $alg === -8 && ($cose[-1] ?? null) === 6 && strlen((string) ($cose[-2] ?? '')) === 32) {
            return 'ed25519:'.base64_encode($cose[-2]);
        }
        throw new WebAuthnException(__('Ten typ klucza nie jest obsługiwany (wymagany ES256, RS256 albo Ed25519).'));
    }

    public function verifySignature(string $publicKey, string $data, string $signature): bool
    {
        if (str_starts_with($publicKey, 'ed25519:')) {
            $key = base64_decode(substr($publicKey, 8), true);

            return $key !== false && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
                && sodium_crypto_sign_verify_detached($signature, $data, $key);
        }

        return openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    public function rpId(Request $request): string
    {
        return (string) (config('virthub.webauthn_rp_id') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: $request->getHost());
    }

    /** @return list<string> */
    private function origins(Request $request): array
    {
        $app = (string) config('app.url');
        $origins = [$request->getSchemeAndHttpHost()];
        if ($app !== '') {
            $parts = parse_url($app);
            $origins[] = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        return array_values(array_unique($origins));
    }

    /** Stały identyfikator konta w kluczu (bez e-maila — ten może się zmienić). */
    private function userHandle(User $user): string
    {
        return hash_hmac('sha256', 'webauthn-user:'.$user->id, (string) config('app.key'), true);
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END PUBLIC KEY-----';
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64u(string $text): string
    {
        $bin = base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $bin === false ? '' : $bin;
    }

    public static function randomName(): string
    {
        return __('Klucz :n', ['n' => Str::upper(Str::random(4))]);
    }
}
