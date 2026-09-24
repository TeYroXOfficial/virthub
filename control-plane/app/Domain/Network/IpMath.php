<?php

namespace App\Domain\Network;

use InvalidArgumentException;

/**
 * Arytmetyka adresów IPv4 i IPv6 na postaci binarnej z inet_pton.
 *
 * ip2long obsługuje tylko IPv4, a adres IPv6 nie mieści się w int — dlatego
 * liczymy na 4- lub 16-bajtowych ciągach. Porównanie takich ciągów tej samej
 * długości (strcmp) daje ten sam porządek co porównanie adresów.
 */
final class IpMath
{
    /**
     * Rozkłada CIDR na sieć (z wyzerowanymi bitami hosta), długość prefiksu i wersję.
     *
     * @return array{network: string, bits: int, version: int}
     */
    public static function parseCidr(string $cidr): array
    {
        $parts = explode('/', trim($cidr));

        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            throw new InvalidArgumentException("Nieprawidłowa podsieć CIDR: {$cidr}.");
        }

        $binary = @inet_pton($parts[0]);

        if ($binary === false) {
            throw new InvalidArgumentException("Nieprawidłowy adres sieci: {$parts[0]}.");
        }

        $version = strlen($binary) === 4 ? 4 : 6;
        $bits = (int) $parts[1];

        if ($bits > strlen($binary) * 8) {
            throw new InvalidArgumentException("Prefiks /{$bits} jest za długi dla IPv{$version}.");
        }

        return [
            'network' => $binary & self::mask($bits, strlen($binary)),
            'bits' => $bits,
            'version' => $version,
        ];
    }

    /** Postać kanoniczna CIDR, np. „2001:DB8:0::/48" → „2001:db8::/48". */
    public static function normalizeCidr(string $cidr): string
    {
        $parsed = self::parseCidr($cidr);

        return inet_ntop($parsed['network']).'/'.$parsed['bits'];
    }

    /** Adres sieci, np. „203.0.113.7/24" → „203.0.113.0". */
    public static function networkAddress(string $cidr): string
    {
        return inet_ntop(self::parseCidr($cidr)['network']);
    }

    public static function version(string $address): ?int
    {
        $binary = @inet_pton($address);

        return $binary === false ? null : (strlen($binary) === 4 ? 4 : 6);
    }

    public static function normalize(string $address): string
    {
        $binary = @inet_pton($address);

        if ($binary === false) {
            throw new InvalidArgumentException("Nieprawidłowy adres IP: {$address}.");
        }

        return inet_ntop($binary);
    }

    public static function contains(string $cidr, string $address): bool
    {
        $parsed = self::parseCidr($cidr);
        $binary = @inet_pton($address);

        if ($binary === false || strlen($binary) !== strlen($parsed['network'])) {
            return false;
        }

        return ($binary & self::mask($parsed['bits'], strlen($binary))) === $parsed['network'];
    }

    /** Ostatni adres podsieci (dla IPv4 — rozgłoszeniowy). */
    public static function lastAddress(string $cidr): string
    {
        $parsed = self::parseCidr($cidr);
        $length = strlen($parsed['network']);

        return inet_ntop($parsed['network'] | ~self::mask($parsed['bits'], $length));
    }

    /** Adres przesunięty o $offset względem $base. */
    public static function add(string $base, int $offset): string
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Przesunięcie adresu nie może być ujemne.');
        }

        $binary = inet_pton($base);
        $carry = $offset;

        for ($i = strlen($binary) - 1; $i >= 0 && $carry > 0; $i--) {
            $sum = ord($binary[$i]) + ($carry & 0xFF);
            $carry = ($carry >> 8) + ($sum >> 8);
            $binary[$i] = chr($sum & 0xFF);
        }

        if ($carry > 0) {
            throw new InvalidArgumentException('Przesunięcie wychodzi poza przestrzeń adresową.');
        }

        return inet_ntop($binary);
    }

    /**
     * Odległość $address od $base (w adresach). Null, gdy $address leży przed
     * $base albo różnica nie mieści się w int.
     */
    public static function offset(string $base, string $address): ?int
    {
        $from = inet_pton($base);
        $to = inet_pton($address);

        if (strlen($from) !== strlen($to) || strcmp($to, $from) < 0) {
            return null;
        }

        $result = '';
        $borrow = 0;

        for ($i = strlen($to) - 1; $i >= 0; $i--) {
            $diff = ord($to[$i]) - ord($from[$i]) - $borrow;
            $borrow = $diff < 0 ? 1 : 0;
            $result = chr(($diff + 256) & 0xFF).$result;
        }

        $result = str_pad($result, 16, "\0", STR_PAD_LEFT);

        // Starsze 8 bajtów muszą być zerowe, a najstarszy bit młodszych
        // wolny — inaczej wynik nie zmieści się w int ze znakiem.
        if (ltrim(substr($result, 0, 8), "\0") !== '' || ord($result[8]) & 0x80) {
            return null;
        }

        return unpack('J', substr($result, 8))[1];
    }

    public static function compare(string $a, string $b): int
    {
        return strcmp(inet_pton($a), inet_pton($b)) <=> 0;
    }

    /** Liczba adresów w podsieci; PHP_INT_MAX, gdy nie mieści się w int. */
    public static function size(string $cidr): int
    {
        $parsed = self::parseCidr($cidr);
        $hostBits = strlen($parsed['network']) * 8 - $parsed['bits'];

        return $hostBits >= 63 ? PHP_INT_MAX : 2 ** $hostBits;
    }

    private static function mask(int $bits, int $length): string
    {
        $mask = str_repeat("\xFF", intdiv($bits, 8));

        if ($bits % 8) {
            $mask .= chr((0xFF << (8 - $bits % 8)) & 0xFF);
        }

        return str_pad($mask, $length, "\0");
    }
}
