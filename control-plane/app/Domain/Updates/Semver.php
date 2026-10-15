<?php

namespace App\Domain\Updates;

/**
 * Wersje semantyczne (semver 2.0.0) — ta sama kolejność co w portalu:
 * wersja z prerelease jest niższa od tej samej bez niego, identyfikatory
 * liczbowe porównujemy jako liczby.
 */
final class Semver
{
    private const PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
        .'(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'
        .'(?:\+[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*)?$/';

    public static function valid(?string $version): bool
    {
        return $version !== null && strlen($version) <= 60 && preg_match(self::PATTERN, $version) === 1;
    }

    public static function compare(string $a, string $b): int
    {
        preg_match(self::PATTERN, $a, $x);
        preg_match(self::PATTERN, $b, $y);
        if (! $x || ! $y) {
            throw new \InvalidArgumentException("Nieprawidłowa wersja: {$a} / {$b}");
        }
        for ($i = 1; $i <= 3; $i++) {
            if ((int) $x[$i] !== (int) $y[$i]) {
                return (int) $x[$i] <=> (int) $y[$i];
            }
        }
        $px = $x[4] ?? '';
        $py = $y[4] ?? '';
        if ($px === $py) {
            return 0;
        }
        if ($px === '' || $py === '') {
            return $px === '' ? 1 : -1;
        }
        $l = explode('.', $px);
        $r = explode('.', $py);
        for ($i = 0; $i < max(count($l), count($r)); $i++) {
            if (! isset($l[$i]) || ! isset($r[$i])) {
                return isset($l[$i]) ? 1 : -1;
            }
            $cmp = match (true) {
                ctype_digit($l[$i]) && ctype_digit($r[$i]) => (int) $l[$i] <=> (int) $r[$i],
                ctype_digit($l[$i]) => -1,
                ctype_digit($r[$i]) => 1,
                default => strcmp($l[$i], $r[$i]) <=> 0,
            };
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    /** major | minor | patch | prerelease */
    public static function bump(string $from, string $to): string
    {
        [$a, $b] = [explode('.', preg_replace('/[-+].*$/', '', $from)), explode('.', preg_replace('/[-+].*$/', '', $to))];

        return match (true) {
            $a[0] !== $b[0] => 'major',
            $a[1] !== $b[1] => 'minor',
            $a[2] !== $b[2] => 'patch',
            default => 'prerelease',
        };
    }
}
