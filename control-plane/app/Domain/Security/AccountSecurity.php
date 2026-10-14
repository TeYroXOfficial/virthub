<?php

namespace App\Domain\Security;

use App\Models\Setting;
use App\Models\User;
use Symfony\Component\HttpFoundation\IpUtils;

/** Zasady dostępu do konta: dozwolone adresy IP i wymóg 2FA dla personelu. */
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

    public function mustEnableTwoFactor(User $user): bool
    {
        return $user->role !== User::ROLE_CUSTOMER && self::staffMustUseTwoFactor() && ! $this->twoFactor->enabled($user);
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
