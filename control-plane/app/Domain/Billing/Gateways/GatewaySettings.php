<?php

namespace App\Domain\Billing\Gateways;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Ustawienia bramek (Administracja → Billing → Bramki płatności). Sekrety zaszyfrowane kluczem panelu. */
final class GatewaySettings
{
    public const SECRETS = ['stripe.secret_key', 'stripe.webhook_secret', 'paypal.client_secret'];

    public static function get(string $key, string $default = ''): string
    {
        $value = Setting::get('gateway.'.$key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (in_array($key, self::SECRETS, true)) {
            try {
                return Crypt::decryptString($value);
            } catch (Throwable) {
                return ''; // inny APP_KEY — sekret trzeba podać ponownie
            }
        }

        return $value;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== '';
    }

    public static function enabled(string $gateway): bool
    {
        return self::get($gateway.'.enabled') === '1';
    }

    /**
     * Zapis; puste pole sekretu oznacza „bez zmian”.
     *
     * @param  array<string, scalar|null>  $values
     */
    public static function save(array $values): void
    {
        $put = [];
        foreach ($values as $key => $value) {
            if (in_array($key, self::SECRETS, true)) {
                if ($value === null || $value === '') {
                    continue;
                }
                $put['gateway.'.$key] = Crypt::encryptString((string) $value);
            } else {
                $put['gateway.'.$key] = is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');
            }
        }
        Setting::put($put);
    }

    public static function forget(string $key): void
    {
        Setting::put(['gateway.'.$key => null]);
    }
}
