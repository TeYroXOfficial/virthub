<?php

namespace App\Support;

/**
 * Logo systemu jako wbudowany SVG — bez CDN i zewnętrznych plików.
 * Ścieżki z simple-icons (CC0, resources/os-icons), Windows narysowany
 * jako prosty znak czterech kafelków.
 */
final class OsIcon
{
    /** Kolor tła znaczka i kolor logo. */
    private const STYLE = [
        'ubuntu' => ['#E95420', '#fff'], 'debian' => ['#A81D33', '#fff'], 'almalinux' => ['#0F4266', '#fff'],
        'rocky' => ['#10B981', '#fff'], 'centos' => ['#262577', '#fff'], 'fedora' => ['#51A2DA', '#fff'],
        'alpine' => ['#0D597F', '#fff'], 'arch' => ['#1793D1', '#fff'], 'opensuse' => ['#73BA25', '#fff'],
        'redhat' => ['#EE0000', '#fff'], 'linuxmint' => ['#86BE43', '#fff'], 'kali' => ['#557C94', '#fff'],
        'gentoo' => ['#54487A', '#fff'], 'freebsd' => ['#AB2B28', '#fff'], 'manjaro' => ['#35BFA4', '#fff'],
        'nixos' => ['#5277C3', '#fff'], 'windows' => ['#0078D4', '#fff'], 'linux' => ['#FCC624', '#1b1b1b'],
    ];

    /** @var array<string, string|null> */
    private static array $paths = [];

    /** @return array{0: string, 1: string} */
    public static function colors(?string $family): array
    {
        return self::STYLE[$family ?? ''] ?? ['#677789', '#fff'];
    }

    /** Wnętrze <svg viewBox="0 0 24 24"> albo null, gdy dla rodziny nie ma logo. */
    public static function path(?string $family): ?string
    {
        $family = preg_replace('/[^a-z0-9]/', '', (string) $family);

        if (! array_key_exists($family, self::$paths)) {
            $file = resource_path("os-icons/{$family}.svg");
            self::$paths[$family] = $family !== '' && is_file($file) ? trim((string) file_get_contents($file)) : null;
        }

        return self::$paths[$family];
    }
}
