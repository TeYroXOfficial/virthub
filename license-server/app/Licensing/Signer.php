<?php

namespace App\Licensing;

use RuntimeException;

/**
 * Podpisy Ed25519 — tokeny licencji i paczki addonów. Format wiadomości musi
 * być zgodny z panelem (App\Domain\Licensing\Signature w control-plane).
 */
class Signer
{
    private function secret(): string
    {
        $key = base64_decode((string) config('licensing.signing_key'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Brak klucza podpisu: ustaw LICENSE_SIGNING_KEY (php artisan license:keygen).');
        }

        return $key;
    }

    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secret()));
    }

    public function sign(string $message): string
    {
        return base64_encode(sodium_crypto_sign_detached($message, $this->secret()));
    }

    /** @return array{token:string, signature:string} */
    public function token(array $payload): array
    {
        $token = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');

        return ['token' => $token, 'signature' => $this->sign($token)];
    }

    public static function packageMessage(string $addon, string $version, string $sha256): string
    {
        return "virthub-addon\n{$addon}\n{$version}\n{$sha256}";
    }
}
