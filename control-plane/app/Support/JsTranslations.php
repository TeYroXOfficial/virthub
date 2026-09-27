<?php

namespace App\Support;

/**
 * Tłumaczenia tekstów używanych w skryptach public/js (funkcja vhT).
 * Wstrzykujemy tylko te klucze, a nie cały słownik — strona zostaje lekka.
 */
final class JsTranslations
{
    /** Teksty ze skryptów — źródłowe, polskie. */
    public const KEYS = [
        'Procesor', 'Pamięć RAM', 'Użyte', 'Dysk I/O', 'Odczyt', 'Zapis', 'Sieć', 'Pobieranie', 'Wysyłanie', 'Czas',
        'na żywo', 'brak danych z węzła', 'łączenie…', 'Nie udało się pobrać historii.', 'Uruchamiam reinstalację…',
        'Zlecenie czeka w kolejce panelu…', 'Węzeł przyjął zlecenie — zaraz zacznie.',
        'Węzeł pobiera obraz systemu. Przy pierwszym użyciu tego systemu może to potrwać kilka minut.',
        'Węzeł pobiera obraz systemu: ', 'Gotowe! Serwer działa.', 'Operacja nie powiodła się',
        'Szczegóły pojawią się na stronie.', 'Odświeżam panel…', 'Wybierz system operacyjny.',
    ];

    /** @return array<string, string> */
    public static function for(string $locale): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $translated = __($key, [], $locale);
            if ($translated !== $key) {
                $out[$key] = $translated;
            }
        }

        return $out;
    }
}
