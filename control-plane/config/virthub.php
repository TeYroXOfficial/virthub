<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nazwa produktu
    |--------------------------------------------------------------------------
    | Panel jest białą etykietą — nazwa i kolor wiodący pochodzą stąd, a nie z
    | zaszytych wartości w widokach.
    */

    'brand' => env('VIRTHUB_BRAND', 'VirtHub'),

    /*
    |--------------------------------------------------------------------------
    | Konsola
    |--------------------------------------------------------------------------
    | Przeglądarka łączy się z /console-ws/{sesja} na tym samym serwerze —
    | nginx kieruje to do przekaźnika konsoli (console-proxy/), który wymienia
    | sesję w panelu wspólnym sekretem i zestawia tunel do agenta węzła.
    | Pusty sekret = przekaźnik niewdrożony; panel powie to wprost.
    */

    'console_secret' => env('VIRTHUB_CONSOLE_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Aktualizacje
    |--------------------------------------------------------------------------
    | Repozytorium i gałąź, z którymi panel porównuje wersje. Samo pobieranie
    | kodu robią uprzywilejowane usługi systemd na serwerach (panel tylko
    | zostawia zlecenie w update_dir) — patrz infra/update-panel.sh
    | i node-agent/scripts/update-node.sh.
    */

    'update_repo' => env('VIRTHUB_UPDATE_REPO', 'TeYroXOfficial/virthub'),
    'update_branch' => env('VIRTHUB_UPDATE_BRANCH', 'main'),
    'update_dir' => env('VIRTHUB_UPDATE_DIR', '/var/lib/virthub-panel'),
    'update_unit' => env('VIRTHUB_UPDATE_UNIT', '/etc/systemd/system/virthub-panel-update.path'),

    /*
    |--------------------------------------------------------------------------
    | Kod agenta serwowany przy rejestracji hypervisora
    |--------------------------------------------------------------------------
    | Instalator uruchamiany na nowym węźle pobiera agenta stąd. Domyślnie
    | katalog obok panelu, zgodnie z układem monorepo; przy wdrożeniu samego
    | panelu wskaż ścieżkę, pod którą wgrałeś katalog `node-agent`.
    */

    'agent_source_path' => env('VIRTHUB_AGENT_SOURCE_PATH', base_path('../node-agent')),

    /*
    |--------------------------------------------------------------------------
    | Katalog szablonów kontenerów
    |--------------------------------------------------------------------------
    | Obrazy z images.linuxcontainers.org — oficjalnego serwera obrazów
    | projektu Linux Containers, z którego korzysta też Proxmox. Wariant
    | „cloud" zawiera cloud-init, dzięki któremu kontener dostaje hasło, klucz
    | SSH i adresację przy pierwszym starcie.
    |
    | Tylko dystrybucje z systemd i pakietem openssh-server — instalator
    | kontenera doinstalowuje serwer SSH pod tą nazwą i uruchamia go przez
    | systemctl. Alpine, Arch czy openSUSE mają inne nazwy pakietów i nie
    | zadziałałyby bez zmian w agencie.
    |
    | Administrator dodaje szablon jednym kliknięciem, a panel rozsyła go na
    | wszystkie węzły kontenerów. Własny alias też da się dodać ręcznie.
    */

    'lxc_catalog' => [
        'debian-13' => ['name' => 'Debian 13', 'family' => 'debian', 'version' => '13', 'alias' => 'debian/13/cloud'],
        'debian-12' => ['name' => 'Debian 12', 'family' => 'debian', 'version' => '12', 'alias' => 'debian/12/cloud'],
        'ubuntu-2404' => ['name' => 'Ubuntu 24.04 LTS', 'family' => 'ubuntu', 'version' => '24.04', 'alias' => 'ubuntu/24.04/cloud'],
        'ubuntu-2204' => ['name' => 'Ubuntu 22.04 LTS', 'family' => 'ubuntu', 'version' => '22.04', 'alias' => 'ubuntu/22.04/cloud'],
        'almalinux-9' => ['name' => 'AlmaLinux 9', 'family' => 'almalinux', 'version' => '9', 'alias' => 'almalinux/9/cloud'],
        'rocky-9' => ['name' => 'Rocky Linux 9', 'family' => 'rocky', 'version' => '9', 'alias' => 'rockylinux/9/cloud'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limity
    |--------------------------------------------------------------------------
    */

    'limits' => [
        // Ile maszyn może zamówić samodzielnie jeden klient. Zabezpieczenie
        // przed wyczerpaniem floty przez pojedyncze konto (albo skrypt).
        'servers_per_customer' => (int) env('VIRTHUB_SERVERS_PER_CUSTOMER', 10),

        // Retencja próbek telemetrii w dniach.
        'metrics_retention_days' => (int) env('VIRTHUB_METRICS_RETENTION_DAYS', 30),
    ],

    // Co wlicza się do limitu transferu pakietu: total (obie strony), out, in.
    'traffic_counting' => env('VIRTHUB_TRAFFIC_COUNTING', 'total'),
];
