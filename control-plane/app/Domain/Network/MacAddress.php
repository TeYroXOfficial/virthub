<?php

namespace App\Domain\Network;

use Illuminate\Validation\ValidationException;

/** MAC karty maszyny: aa:bb:cc:dd:ee:ff, adres unicast — tak samo sprawdza go agent. */
final class MacAddress
{
    /** @throws ValidationException */
    public static function normalize(?string $value, string $field = 'mac_address'): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $mac = strtolower(str_replace('-', ':', $value));
        if (! preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $mac)) {
            throw ValidationException::withMessages([$field => __('MAC musi mieć postać aa:bb:cc:dd:ee:ff.')]);
        }
        if (hexdec(substr($mac, 0, 2)) & 1) {
            throw ValidationException::withMessages([$field => __('To adres grupowy (multicast) — karta maszyny musi mieć MAC unicast.')]);
        }
        if ($mac === '00:00:00:00:00:00') {
            throw ValidationException::withMessages([$field => __('Zerowy MAC nie może być adresem karty.')]);
        }

        return $mac;
    }
}
