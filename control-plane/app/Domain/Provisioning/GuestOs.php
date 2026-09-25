<?php

namespace App\Domain\Provisioning;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Models\Server;

/**
 * System zainstalowany w maszynie, odczytany z jej wnętrza (qemu-guest-agent
 * albo /etc/os-release kontenera). Szablon mówi, co było zainstalowane na
 * początku — po instalacji z ISO albo ręcznej zmianie to już nieprawda.
 */
class GuestOs
{
    /** Jak często odświeżać odczyt działającej maszyny. */
    public const REFRESH_HOURS = 6;

    /** Identyfikator z os-release / guest-agenta → rodzina (ikona) w panelu. */
    private const FAMILIES = [
        'ubuntu' => 'ubuntu', 'debian' => 'debian', 'almalinux' => 'almalinux', 'rocky' => 'rocky',
        'rockylinux' => 'rocky', 'centos' => 'centos', 'fedora' => 'fedora', 'rhel' => 'redhat',
        'redhat' => 'redhat', 'alpine' => 'alpine', 'arch' => 'arch', 'archlinux' => 'arch',
        'opensuse' => 'opensuse', 'opensuse-leap' => 'opensuse', 'opensuse-tumbleweed' => 'opensuse',
        'sles' => 'opensuse', 'linuxmint' => 'linuxmint', 'kali' => 'kali', 'gentoo' => 'gentoo',
        'manjaro' => 'manjaro', 'nixos' => 'nixos', 'freebsd' => 'freebsd', 'mswindows' => 'windows',
        'windows' => 'windows',
    ];

    public static function familyFor(?string $id): ?string
    {
        return $id === null ? null : (self::FAMILIES[strtolower($id)] ?? 'linux');
    }

    /** Odczytuje system z węzła. Zwraca false, gdy się nie udało (poprzedni odczyt zostaje). */
    public function detect(Server $server): bool
    {
        if ($server->agent_uuid === null || $server->hypervisor === null) {
            return false;
        }

        try {
            $info = (new AgentClient($server->hypervisor))->guestOs($server->agent_uuid);
        } catch (AgentException) {
            // Maszyna wyłączona, brak guest-agenta, stary agent — spróbujemy później.
            $server->forceFill(['guest_os_checked_at' => now()])->save();

            return false;
        }

        if (empty($info['id']) && empty($info['pretty_name'])) {
            return false;
        }

        // Wartości pochodzą z wnętrza maszyny klienta — przycinamy do kolumn,
        // a identyfikator zawężamy do znaków spotykanych w os-release.
        $clip = fn ($v, int $n) => is_string($v) && $v !== '' ? mb_substr(trim($v), 0, $n) : null;
        $id = $clip($info['id'] ?? null, 40);

        $server->forceFill([
            'guest_os_id' => $id !== null ? (preg_replace('/[^a-z0-9._-]/', '', strtolower($id)) ?: null) : null,
            'guest_os_name' => $clip($info['pretty_name'] ?? $info['name'] ?? null, 120),
            'guest_os_version' => $clip($info['version'] ?? null, 40),
            'guest_os_checked_at' => now(),
        ])->save();

        return true;
    }

    /** Czy działającą maszynę warto odpytać w tym obiegu. */
    public function isDue(Server $server): bool
    {
        return $server->guest_os_checked_at === null
            || $server->guest_os_checked_at->lt(now()->subHours(self::REFRESH_HOURS));
    }
}
