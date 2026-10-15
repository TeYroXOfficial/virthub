<?php

namespace App\Domain\Security;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Zasady dostępu do konta: dozwolone adresy IP, wymóg drugiego składnika
 * (kod z aplikacji albo klucz dostępu) dla personelu i — opcjonalnie — klientów,
 * przypięcie sesji do adresu IP i przeglądarki.
 */
class AccountSecurity
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    /** @return list<string> */
    public function allowedIps(User $user): array
    {
        return self::parseIps((string) $user->allowed_ips);
    }

    public function ipAllowed(User $user, string $ip): bool
    {
        $list = $this->allowedIps($user);

        return $list === [] || IpUtils::checkIp($ip, $list);
    }

    public static function staffMustUseTwoFactor(): bool
    {
        return (bool) Setting::get('security.staff_2fa', false);
    }

    public static function customersMustUseTwoFactor(): bool
    {
        return (bool) Setting::get('security.customer_2fa', false);
    }

    /** Kod TOTP albo co najmniej jeden klucz dostępu (passkey). */
    public function hasSecondFactor(User $user): bool
    {
        return $this->twoFactor->enabled($user) || $user->passkeys()->exists();
    }

    public function mustEnableTwoFactor(User $user): bool
    {
        $required = $user->role === User::ROLE_CUSTOMER ? self::customersMustUseTwoFactor() : self::staffMustUseTwoFactor();

        return $required && ! $this->hasSecondFactor($user);
    }

    /** Odcisk sesji przypiętej do adresu i przeglądarki. */
    public static function fingerprint(Request $request): string
    {
        return hash('sha256', $request->ip().'|'.$request->userAgent());
    }

    /**
     * Lista adresów/podsieci (po jednej w linii, przecinkach lub spacjach).
     *
     * @return list<string>
     */
    public static function parseIps(string $text): array
    {
        $items = preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($items));
    }

    /** Zwraca błędne pozycje listy (do komunikatu walidacji). */
    public static function invalidIps(array $items): array
    {
        return array_values(array_filter($items, function (string $item) {
            [$ip, $mask] = array_pad(explode('/', $item, 2), 2, null);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return true;
            }
            if ($mask === null) {
                return false;
            }
            $max = str_contains($ip, ':') ? 128 : 32;

            return ! ctype_digit($mask) || (int) $mask > $max;
        }));
    }
}
