<?php

namespace App\Domain\Billing;

use Illuminate\Support\Carbon;

/** Cykle rozliczeń: godzinowy i dzienny z portfela, reszta — faktury okresowe. */
final class Cycle
{
    public const HOURLY = 'hourly';

    public const DAILY = 'daily';

    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    public const SEMIANNUALLY = 'semiannually';

    public const ANNUALLY = 'annually';

    public const ALL = [self::HOURLY, self::DAILY, self::MONTHLY, self::QUARTERLY, self::SEMIANNUALLY, self::ANNUALLY];

    /** Naliczane z portfela co godzinę / dobę (jak VirtFusion self service). */
    public static function metered(string $cycle): bool
    {
        return $cycle === self::HOURLY || $cycle === self::DAILY;
    }

    public static function add(Carbon $from, string $cycle): Carbon
    {
        $date = $from->copy();

        return match ($cycle) {
            self::HOURLY => $date->addHour(),
            self::DAILY => $date->addDay(),
            self::MONTHLY => $date->addMonthNoOverflow(),
            self::QUARTERLY => $date->addMonthsNoOverflow(3),
            self::SEMIANNUALLY => $date->addMonthsNoOverflow(6),
            self::ANNUALLY => $date->addYearNoOverflow(),
            default => throw new \InvalidArgumentException("Nieznany cykl {$cycle}"),
        };
    }

    /** Przybliżona liczba godzin w cyklu — do porównań cen „za miesiąc”. */
    public static function hours(string $cycle): int
    {
        return match ($cycle) {
            self::HOURLY => 1,
            self::DAILY => 24,
            self::MONTHLY => 730,
            self::QUARTERLY => 2190,
            self::SEMIANNUALLY => 4380,
            default => 8760,
        };
    }

    public static function label(string $cycle): string
    {
        return match ($cycle) {
            self::HOURLY => __('godzinowo'),
            self::DAILY => __('dziennie'),
            self::MONTHLY => __('miesięcznie'),
            self::QUARTERLY => __('kwartalnie'),
            self::SEMIANNUALLY => __('półrocznie'),
            self::ANNUALLY => __('rocznie'),
            default => $cycle,
        };
    }

    /** Krótki dopisek przy cenie: „/ godz.”, „/ mies.” … */
    public static function per(string $cycle): string
    {
        return match ($cycle) {
            self::HOURLY => __('/ godz.'),
            self::DAILY => __('/ dzień'),
            self::MONTHLY => __('/ mies.'),
            self::QUARTERLY => __('/ kwartał'),
            self::SEMIANNUALLY => __('/ pół roku'),
            self::ANNUALLY => __('/ rok'),
            default => '',
        };
    }
}
