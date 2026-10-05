<?php

namespace App\Domain\Billing;

use App\Models\Setting;

/** Ustawienia billingu (Administracja → Billing → Ustawienia), z wartościami domyślnymi. */
final class Billing
{
    public const DEFAULTS = [
        'enabled' => '0',
        'currency' => 'PLN',
        'tax_rate' => '23',          // procent
        'prices_include_tax' => '1', // ceny w katalogu brutto
        'seller_name' => '',
        'seller_address' => '',
        'seller_tax_id' => '',
        'invoice_prefix' => 'FV/{Y}/',
        'invoice_notes' => '',
        'due_days' => '7',            // termin płatności nowej faktury
        'renewal_days' => '7',        // faktura odnowienia tyle dni przed końcem okresu
        'reminder_days' => '2',       // przypomnienie tyle dni przed terminem
        'suspend_days' => '3',        // zawieszenie tyle dni po terminie
        'terminate_days' => '14',     // usunięcie tyle dni po zawieszeniu za brak płatności
        'inactive_delete' => '7d',    // usunięcie po takim czasie zawieszenia za brak aktywności (kod okresu)
        'auto_pay' => '1',            // odnowienia opłacane z portfela, gdy są środki
        'min_topup' => '10',
        'max_topup' => '10000',
        'min_balance_metered' => '5', // minimalne saldo do zamówienia usługi godzinowej
        'low_balance_hours' => '24',  // ostrzeżenie, gdy saldo starczy na mniej godzin
    ];

    public static function get(string $key): string
    {
        return (string) Setting::get('billing.'.$key, self::DEFAULTS[$key] ?? '');
    }

    /** @param  array<string, scalar|null>  $values */
    public static function save(array $values): void
    {
        $put = [];
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                $put['billing.'.$key] = is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? '');
            }
        }
        Setting::put($put);
    }

    public static function enabled(): bool
    {
        return self::get('enabled') === '1';
    }

    public static function currency(): string
    {
        return strtoupper(self::get('currency')) ?: 'PLN';
    }

    /** Stawka VAT w punktach bazowych (23% → 2300). */
    public static function taxRate(): int
    {
        return (int) round((float) str_replace(',', '.', self::get('tax_rate')) * 100);
    }

    public static function pricesIncludeTax(): bool
    {
        return self::get('prices_include_tax') === '1';
    }

    public static function int(string $key): int
    {
        return max(0, (int) self::get($key));
    }

    public static function money(string $key): int
    {
        return Money::valid(self::get($key)) ? Money::parse(self::get($key)) : 0;
    }

    /** Cena katalogowa → kwota brutto, którą płaci klient. */
    public static function gross(int $price): int
    {
        return self::pricesIncludeTax() ? $price : $price + Money::mulDiv($price, self::taxRate(), 10000);
    }

    /** Domyślny czas od zawieszenia za brak aktywności do usunięcia (kod okresu). */
    public static function inactiveDelete(): string
    {
        $code = self::get('inactive_delete');

        return Cycle::valid($code) ? $code : '7d';
    }

    /** @return array{name:string, address:string, tax_id:string} */
    public static function seller(): array
    {
        return ['name' => self::get('seller_name'), 'address' => self::get('seller_address'), 'tax_id' => self::get('seller_tax_id')];
    }
}
