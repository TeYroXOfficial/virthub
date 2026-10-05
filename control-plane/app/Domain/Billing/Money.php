<?php

namespace App\Domain\Billing;

use InvalidArgumentException;

/**
 * Kwoty jako liczby całkowite z dokładnością 0,0001 waluty (10 000 = 1 zł).
 * Godzinne stawki bywają ułamkami grosza, więc grosze to za mało; float —
 * nigdy w pieniądzach. Bez bcmath: zakres int64 wystarcza z ogromnym zapasem.
 */
final class Money
{
    public const SCALE = 10000;

    /** „12,50”, „12.5”, „1 200,00” → 125000; najwyżej 4 miejsca po przecinku. */
    public static function parse(string|int|null $value): int
    {
        if (is_int($value)) {
            return $value * self::SCALE;
        }
        $value = str_replace([' ', "\u{00A0}"], '', trim((string) $value));
        $value = str_replace(',', '.', $value);
        if (! preg_match('/^(-)?(\d{1,12})(?:\.(\d{1,4}))?$/', $value, $m)) {
            throw new InvalidArgumentException(__('Nieprawidłowa kwota: :value', ['value' => $value]));
        }
        $amount = (int) $m[2] * self::SCALE + (int) str_pad($m[3] ?? '', 4, '0');

        return $m[1] === '-' ? -$amount : $amount;
    }

    /** Czy tekst jest poprawną kwotą (do walidacji formularzy). */
    public static function valid(?string $value): bool
    {
        try {
            self::parse($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** Zaokrąglenie do pełnych groszy (połówki od zera). */
    public static function roundCents(int $amount): int
    {
        return self::divRound($amount, 100) * 100;
    }

    /** amount × numerator / denominator z zaokrągleniem połówek od zera. */
    public static function mulDiv(int $amount, int $numerator, int $denominator): int
    {
        return self::divRound($amount * $numerator, $denominator);
    }

    /** Liczba do formularza i bramek: „12.50” (kropka, bez separatora tysięcy). */
    public static function toDecimal(int $amount, int $decimals = 2): string
    {
        $rounded = $decimals === 4 ? $amount : self::divRound($amount, 10 ** (4 - $decimals));
        $sign = $rounded < 0 ? '-' : '';
        $abs = abs($rounded);
        $unit = 10 ** $decimals;
        $whole = intdiv($abs, $unit);
        $fraction = $abs % $unit;

        return $sign.$whole.($decimals > 0 ? '.'.str_pad((string) $fraction, $decimals, '0', STR_PAD_LEFT) : '');
    }

    /** Wartość w formularzu bez zbędnych zer: 125000 → „12.50”, 123 → „0.0123”. */
    public static function input(int $amount): string
    {
        return $amount % 100 === 0 ? self::toDecimal($amount) : rtrim(self::toDecimal($amount, 4), '0');
    }

    /** „1 234,50 PLN” — ułamki grosza (stawki godzinowe) do 4 miejsc. */
    public static function format(int $amount, ?string $currency = null): string
    {
        $decimals = $amount % 100 === 0 ? 2 : 4;
        $text = self::toDecimal($amount, $decimals);
        [$whole, $fraction] = explode('.', ltrim($text, '-'));
        if ($decimals === 4) {
            $fraction = rtrim($fraction, '0');
            $fraction = strlen($fraction) < 2 ? str_pad($fraction, 2, '0') : $fraction;
        }
        $whole = number_format((int) $whole, 0, '', "\u{00A0}");
        $currency ??= Billing::currency();

        return ($amount < 0 ? '-' : '').$whole.','.$fraction.' '.$currency;
    }

    private static function divRound(int $value, int $divisor): int
    {
        $q = intdiv($value, $divisor);
        $r = $value % $divisor;
        if (abs($r) * 2 >= $divisor) {
            $q += $value < 0 ? -1 : 1;
        }

        return $q;
    }
}
