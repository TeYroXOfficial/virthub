<?php

namespace App\Domain\Security;

/**
 * Minimalny dekoder CBOR (RFC 8949) dla WebAuthn: liczby, ciągi bajtów i tekstu,
 * tablice, mapy, tagi (pomijane) i wartości proste. Bez długości nieokreślonej
 * poza ciągami — WebAuthn używa wyłącznie postaci kanonicznej.
 */
final class Cbor
{
    private const MAX_DEPTH = 16;

    public static function decode(string $data, bool $allowTrailing = false): mixed
    {
        $offset = 0;
        $value = self::item($data, $offset, 0);
        if (! $allowTrailing && $offset !== strlen($data)) {
            throw new WebAuthnException(__('Nieprawidłowe dane CBOR.'));
        }

        return $value;
    }

    private static function item(string $data, int &$offset, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH || $offset >= strlen($data)) {
            throw new WebAuthnException(__('Nieprawidłowe dane CBOR.'));
        }
        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            return match ($info) {
                20 => false, 21 => true, 22, 23 => null,
                25, 26, 27 => self::float($data, $offset, $info),
                default => throw new WebAuthnException(__('Nieprawidłowe dane CBOR.')),
            };
        }
        $length = self::length($data, $offset, $info);

        return match ($major) {
            0 => $length,
            1 => -1 - $length,
            2, 3 => self::bytes($data, $offset, $length),
            4 => self::array($data, $offset, $length, $depth),
            5 => self::map($data, $offset, $length, $depth),
            6 => self::item($data, $offset, $depth + 1),
        };
    }

    private static function length(string $data, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $size = match ($info) {
            24 => 1, 25 => 2, 26 => 4, 27 => 8, default => throw new WebAuthnException(__('Nieprawidłowe dane CBOR.'))
        };
        $chunk = self::take($data, $offset, $size);
        $value = 0;
        foreach (str_split($chunk) as $byte) {
            $value = ($value << 8) | ord($byte);
        }
        if ($value < 0) {
            throw new WebAuthnException(__('Nieprawidłowe dane CBOR.'));
        }

        return $value;
    }

    private static function bytes(string $data, int &$offset, int $length): string
    {
        return self::take($data, $offset, $length);
    }

    private static function array(string $data, int &$offset, int $length, int $depth): array
    {
        $out = [];
        for ($i = 0; $i < $length; $i++) {
            $out[] = self::item($data, $offset, $depth + 1);
        }

        return $out;
    }

    private static function map(string $data, int &$offset, int $length, int $depth): array
    {
        $out = [];
        for ($i = 0; $i < $length; $i++) {
            $key = self::item($data, $offset, $depth + 1);
            if (! is_int($key) && ! is_string($key)) {
                throw new WebAuthnException(__('Nieprawidłowe dane CBOR.'));
            }
            $out[$key] = self::item($data, $offset, $depth + 1);
        }

        return $out;
    }

    private static function float(string $data, int &$offset, int $info): float
    {
        $size = [25 => 2, 26 => 4, 27 => 8][$info];
        $chunk = self::take($data, $offset, $size);
        if ($size === 2) {
            $half = unpack('n', $chunk)[1];
            $exp = ($half >> 10) & 0x1F;
            $mant = $half & 0x3FF;
            $val = $exp === 0 ? $mant * 2 ** -24 : ($exp === 31 ? INF : ($mant + 1024) * 2 ** ($exp - 25));

            return ($half & 0x8000) ? -$val : $val;
        }

        return $size === 4 ? unpack('G', $chunk)[1] : unpack('E', $chunk)[1];
    }

    private static function take(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new WebAuthnException(__('Nieprawidłowe dane CBOR.'));
        }
        $chunk = substr($data, $offset, $length);
        $offset += $length;

        return $chunk;
    }
}
