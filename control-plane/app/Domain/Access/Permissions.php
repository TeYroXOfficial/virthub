<?php

namespace App\Domain\Access;

use App\Models\User;

/**
 * Katalog uprawnień i domyślne zestawy ról.
 *
 * Rola wyznacza punkt wyjścia, administrator może go zmienić per użytkownik
 * (kolumna users.permissions). Administrator ma zawsze wszystko — jego
 * uprawnień nie da się ograniczyć, żeby nie odciąć panelu samemu sobie.
 */
final class Permissions
{
    /** Co użytkownik może robić ze swoimi maszynami. */
    public const SERVER = [
        'servers.order' => ['Zamawianie nowych maszyn', 'Tworzenie maszyn z dostępnych pakietów, w ramach limitu.'],
        'servers.power' => ['Zasilanie', 'Uruchamianie, restart, zatrzymanie i odcięcie zasilania.'],
        'servers.console' => ['Konsola', 'Ekran maszyny (noVNC) albo terminal kontenera w przeglądarce.'],
        'servers.firewall' => ['Zapora', 'Zmiana reguł i polityki zapory własnych maszyn.'],
        'servers.snapshots' => ['Snapshoty', 'Tworzenie, przywracanie i usuwanie kopii dysku.'],
        'servers.resize' => ['Zmiana pakietu', 'Przejście na inny pakiet zasobów.'],
        'servers.rebuild' => ['Reinstalacja systemu', 'Postawienie systemu od nowa — kasuje dane na dysku.'],
        'servers.delete' => ['Usuwanie maszyn', 'Trwałe usunięcie maszyny razem z dyskiem.'],
    ];

    /** Działy administracji — tylko dla personelu (rola support lub admin). */
    public const ADMIN = [
        'admin.servers' => ['Maszyny klientów', 'Podgląd i obsługa maszyn wszystkich klientów.'],
        'admin.users' => ['Użytkownicy', 'Zakładanie kont klientów, uprawnienia, blokowanie.'],
        'admin.hypervisors' => ['Hypervisory', 'Dodawanie i konfiguracja węzłów.'],
        'admin.ip_pools' => ['Adresy IP', 'Pule adresów, NAT, grupy węzłów.'],
        'admin.packages' => ['Pakiety', 'Oferta zasobów.'],
        'admin.templates' => ['Szablony', 'Systemy operacyjne do instalacji.'],
        'admin.updates' => ['Aktualizacje', 'Aktualizacja panelu i agentów na węzłach.'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [...array_keys(self::SERVER), ...array_keys(self::ADMIN)];
    }

    /** @return list<string> uprawnienia, które rola w ogóle może mieć */
    public static function assignableFor(string $role): array
    {
        return $role === User::ROLE_CUSTOMER ? array_keys(self::SERVER) : self::all();
    }

    /** @return list<string> */
    public static function defaultsFor(string $role): array
    {
        return match ($role) {
            User::ROLE_ADMIN => self::all(),
            // Wsparcie obsługuje maszyny klientów, ale nie zmienia floty ani oferty.
            User::ROLE_SUPPORT => [...array_keys(self::SERVER), 'admin.servers', 'admin.users'],
            default => array_keys(self::SERVER),
        };
    }

    /**
     * Uprawnienia z formularza/API ograniczone do tego, co rola może mieć.
     *
     * @param  array<int, string>  $requested
     * @return list<string>
     */
    public static function sanitize(string $role, array $requested): array
    {
        return array_values(array_intersect(self::assignableFor($role), $requested));
    }

    public static function label(string $key): string
    {
        return (self::SERVER + self::ADMIN)[$key][0] ?? $key;
    }
}
