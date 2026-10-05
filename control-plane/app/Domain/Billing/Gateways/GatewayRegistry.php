<?php

namespace App\Domain\Billing\Gateways;

/** Bramki płatności dostępne dla klienta (skonfigurowane i włączone). */
class GatewayRegistry
{
    /** @return array<string, string> klucz bramki => nazwa */
    public static function available(): array
    {
        return [];
    }
}
