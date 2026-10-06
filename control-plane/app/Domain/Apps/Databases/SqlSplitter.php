<?php

namespace App\Domain\Apps\Databases;

/**
 * Dzieli skrypt SQL na pojedyncze zapytania (średniki poza napisami
 * i komentarzami). Wystarcza dla zrzutów mysqldump i z panelu;
 * DELIMITER (procedury) nie jest obsługiwany.
 */
final class SqlSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $buf .= $next;
                    $i++;
                } elseif ($c === $quote) {
                    if ($next === $quote) { // podwojony cudzysłów w napisie
                        $buf .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }
            if ($c === '\'' || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;

                continue;
            }
            // Komentarze: -- , # i /* */ (ale /*! … */ to instrukcje warunkowe MySQL — zostają).
            if (($c === '-' && $next === '-' && in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\n", "\r"], true)) || $c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                $buf .= "\n";

                continue;
            }
            if ($c === '/' && $next === '*' && ($sql[$i + 2] ?? '') !== '!') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $buf .= ' ';

                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';

                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }

        return $out;
    }
}
