<?php

namespace App\Domain\Security;

/** Krótki opis przeglądarki i systemu z nagłówka User-Agent (lista sesji). */
class UserAgent
{
    public static function describe(?string $agent): string
    {
        $agent = (string) $agent;
        if ($agent === '') {
            return __('Nieznane urządzenie');
        }
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            str_contains($agent, 'curl/') => 'curl',
            default => null,
        };
        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            (bool) preg_match('/iPhone|iPad/', $agent) => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return trim(($browser ?? __('Przeglądarka')).($os ? ' · '.$os : '')) ?: mb_substr($agent, 0, 60);
    }
}
