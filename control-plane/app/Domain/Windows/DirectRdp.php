<?php

namespace App\Domain\Windows;

use App\Domain\Mail\Notify;
use App\Models\Server;

/**
 * Profil „Direct RDP” szablonu Windows: po instalacji (i reinstalacji) klient
 * dostaje mailem gotowe dane logowania RDP — adres, użytkownika, hasło
 * Administratora — i link do pliku .rdp. Bez profilu mail jak dla Linuksa
 * (hasło tylko w panelu).
 */
class DirectRdp
{
    public const PORT = 3389;

    public static function enabled(Server $server): bool
    {
        return $server->osFamily() === 'windows' && (bool) $server->template?->direct_rdp;
    }

    public static function address(Server $server): ?string
    {
        $ip = $server->primaryIp()?->address;
        if ($ip === null) {
            return null;
        }

        return (str_contains($ip, ':') ? "[$ip]" : $ip).':'.self::PORT;
    }

    /** Plik .rdp dla klienta Pulpitu zdalnego (mstsc, Microsoft Remote Desktop, Remmina). */
    public static function file(Server $server): string
    {
        $lines = [
            'full address:s:'.self::address($server),
            'username:s:Administrator',
            'prompt for credentials:i:1',
            'administrative session:i:1',
            'screen mode id:i:2',
            'use multimon:i:0',
            'session bpp:i:32',
            'audiomode:i:0',
            'redirectclipboard:i:1',
            'authentication level:i:2',
            'enablecredsspsupport:i:1',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    /** Mail z danymi RDP zamiast zwykłego „maszyna gotowa”. */
    public static function notify(Server $server, string $event): bool
    {
        return app(Notify::class)->server($server, 'server.rdp_ready', ['rdp' => [
            'address' => self::address($server) ?? '—',
            'username' => 'Administrator',
            'password' => $server->root_password ?? __('(w panelu)'),
            'file_url' => route('panel.servers.rdp', $server),
        ]]);
    }
}
