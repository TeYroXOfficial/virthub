<?php

namespace App\Domain\Billing;

use Illuminate\Support\Carbon;

/**
 * Okresy rozliczeń: liczba + jednostka (godziny, dni, tygodnie, miesiące, lata).
 *
 * Kod okresu to „3d”, „2w”, „6h”, „18m”… Popularne okresy mają nazwy
 * (hourly, daily, monthly, quarterly, semiannually, annually) — tak zapisane
 * są w bazie od początku i tak nadal powstają, więc nic nie trzeba przeliczać.
 *
 * Okresy liczone w godzinach i dniach są pobierane z portfela z góry za każdy
 * rozpoczęty okres (jak VirtFusion self service); tygodnie, miesiące i lata —
 * fakturą. Okres jednorazowy (bez odnowienia) zawsze opłaca się z góry w całości.
 */
final class Cycle
{
    public const HOURLY = 'hourly';

    public const DAILY = 'daily';

    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    public const SEMIANNUALLY = 'semiannually';

    public const ANNUALLY = 'annually';

    /** Nazwane okresy (kolejność gotowych propozycji w formularzu). */
    public const ALL = [self::HOURLY, self::DAILY, self::MONTHLY, self::QUARTERLY, self::SEMIANNUALLY, self::ANNUALLY];

    public const UNITS = ['h', 'd', 'w', 'm', 'y'];

    /** Najdłuższy okres w danej jednostce. */
    public const MAX = ['h' => 720, 'd' => 365, 'w' => 104, 'm' => 60, 'y' => 10];

    private const NAMED = [
        self::HOURLY => [1, 'h'],
        self::DAILY => [1, 'd'],
        self::MONTHLY => [1, 'm'],
        self::QUARTERLY => [3, 'm'],
        self::SEMIANNUALLY => [6, 'm'],
        self::ANNUALLY => [1, 'y'],
    ];

    /** @return array{0:int, 1:string} [liczba, jednostka] */
    public static function parse(string $cycle): array
    {
        if (isset(self::NAMED[$cycle])) {
            return self::NAMED[$cycle];
        }
        if (preg_match('/^([1-9]\d{0,2})([hdwmy])$/', $cycle, $m) && (int) $m[1] <= self::MAX[$m[2]]) {
            return [(int) $m[1], $m[2]];
        }
        throw new \InvalidArgumentException("Nieznany okres {$cycle}");
    }

    public static function valid(string $cycle): bool
    {
        try {
            self::parse($cycle);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** Kod okresu: nazwa dla popularnych, inaczej „3d”, „2w”… */
    public static function code(int $count, string $unit): string
    {
        if ($unit === 'm' && $count % 12 === 0) {
            [$count, $unit] = [intdiv($count, 12), 'y'];
        }
        $name = array_search([$count, $unit], self::NAMED, true);
        $code = $name !== false ? $name : $count.$unit;
        self::parse($code); // walidacja zakresu

        return $code;
    }

    public static function unit(string $cycle): string
    {
        return self::parse($cycle)[1];
    }

    /** Godziny i dni — pobierane z portfela (gdy okres się odnawia). */
    public static function metered(string $cycle): bool
    {
        return in_array(self::unit($cycle), ['h', 'd'], true);
    }

    public static function add(Carbon $from, string $cycle): Carbon
    {
        [$n, $unit] = self::parse($cycle);
        $date = $from->copy();

        return match ($unit) {
            'h' => $date->addHours($n),
            'd' => $date->addDays($n),
            'w' => $date->addWeeks($n),
            'm' => $date->addMonthsNoOverflow($n),
            'y' => $date->addYearsNoOverflow($n),
        };
    }

    public static function sub(Carbon $from, string $cycle): Carbon
    {
        [$n, $unit] = self::parse($cycle);
        $date = $from->copy();

        return match ($unit) {
            'h' => $date->subHours($n),
            'd' => $date->subDays($n),
            'w' => $date->subWeeks($n),
            'm' => $date->subMonthsNoOverflow($n),
            'y' => $date->subYearsNoOverflow($n),
        };
    }

    /** Przybliżona liczba godzin w okresie — do sortowania i porównań cen „za miesiąc”. */
    public static function hours(string $cycle): int
    {
        [$n, $unit] = self::parse($cycle);

        return $n * ['h' => 1, 'd' => 24, 'w' => 168, 'm' => 730, 'y' => 8760][$unit];
    }

    /** Długość okresu: „3 dni”, „2 tygodnie”, „1 miesiąc”. */
    public static function duration(string $cycle): string
    {
        [$n, $unit] = self::parse($cycle);

        return trans_choice(match ($unit) {
            'h' => ':count godzina|:count godziny|:count godzin',
            'd' => ':count dzień|:count dni|:count dni',
            'w' => ':count tydzień|:count tygodnie|:count tygodni',
            'm' => ':count miesiąc|:count miesiące|:count miesięcy',
            'y' => ':count rok|:count lata|:count lat',
        }, $n);
    }

    /** Nazwa w formularzach: „miesięcznie” dla nazwanych, „co 3 dni” dla pozostałych. */
    public static function label(string $cycle): string
    {
        return match ($cycle) {
            self::HOURLY => __('godzinowo'),
            self::DAILY => __('dziennie'),
            self::MONTHLY => __('miesięcznie'),
            self::QUARTERLY => __('kwartalnie'),
            self::SEMIANNUALLY => __('półrocznie'),
            self::ANNUALLY => __('rocznie'),
            default => __('co :period', ['period' => self::duration($cycle)]),
        };
    }

    /** Dopisek przy cenie: „/ mies.”, „/ 3 dni”… */
    public static function per(string $cycle): string
    {
        return match ($cycle) {
            self::HOURLY => __('/ godz.'),
            self::DAILY => __('/ dzień'),
            self::MONTHLY => __('/ mies.'),
            self::QUARTERLY => __('/ kwartał'),
            self::SEMIANNUALLY => __('/ pół roku'),
            self::ANNUALLY => __('/ rok'),
            default => '/ '.self::duration($cycle),
        };
    }

    /** Nazwy jednostek do formularzy. @return array<string, string> */
    public static function unitLabels(): array
    {
        return ['h' => __('godzin'), 'd' => __('dni'), 'w' => __('tygodni'), 'm' => __('miesięcy'), 'y' => __('lat')];
    }

    /** Sortuje kody okresów od najkrótszego. @param  array<string, mixed>  $byCycle */
    public static function sort(array $byCycle): array
    {
        uksort($byCycle, fn ($a, $b) => self::hours($a) <=> self::hours($b) ?: strcmp($a, $b));

        return $byCycle;
    }
}
