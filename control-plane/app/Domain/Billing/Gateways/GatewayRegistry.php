<?php

namespace App\Domain\Billing\Gateways;

/** Bramki płatności panelu. */
class GatewayRegistry
{
    /** @return array<string, class-string<PaymentGateway>> */
    public const GATEWAYS = [
        'stripe' => StripeGateway::class,
        'paypal' => PayPalGateway::class,
    ];

    public static function get(string $key): ?PaymentGateway
    {
        $class = self::GATEWAYS[$key] ?? null;

        return $class ? app($class) : null;
    }

    /** Bramki dostępne dla klienta (włączone i skonfigurowane). @return array<string, string> klucz => nazwa */
    public static function available(): array
    {
        $out = [];
        foreach (array_keys(self::GATEWAYS) as $key) {
            $gateway = self::get($key);
            if ($gateway->available()) {
                $out[$key] = $gateway->label();
            }
        }

        return $out;
    }
}
