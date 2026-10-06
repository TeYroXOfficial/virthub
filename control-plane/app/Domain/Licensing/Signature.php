<?php

namespace App\Domain\Licensing;

/**
 * Podpisy Ed25519 serwera licencji. Panel ma tylko klucz publiczny, więc
 * może sprawdzić token licencji i paczkę addonu, ale ich nie podrobi.
 */
final class Signature
{
    /** Klucz publiczny serwera licencji (32 bajty) albo null, gdy nie ustawiony / błędny. */
    public static function publicKey(): ?string
    {
        $key = base64_decode((string) config('virthub.license.public_key'), true);

        return $key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    public static function verify(string $message, string $signatureBase64, ?string $publicKey = null): bool
    {
        $publicKey ??= self::publicKey();
        $signature = base64_decode($signatureBase64, true);
        if ($publicKey === null || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    /** Wiadomość podpisywana dla paczki addonu — wiąże identyfikator, wersję i zawartość. */
    public static function packageMessage(string $addon, string $version, string $sha256): string
    {
        return "virthub-addon\n{$addon}\n{$version}\n{$sha256}";
    }
}
