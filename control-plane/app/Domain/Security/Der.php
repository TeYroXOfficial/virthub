<?php

namespace App\Domain\Security;

/** Kodowanie DER potrzebne do zbudowania klucza RSA (SubjectPublicKeyInfo). */
final class Der
{
    public static function sequence(string $content): string
    {
        return "\x30".self::length(strlen($content)).$content;
    }

    public static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::length(strlen($bytes)).$bytes;
    }

    public static function bitString(string $content): string
    {
        return "\x03".self::length(strlen($content) + 1)."\x00".$content;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
