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
    | Proxy konsoli
    |--------------------------------------------------------------------------
    | Adres WebSocket proxy (noVNC/websockify) zestawiającego tunel do gniazda
    | VNC maszyny. Puste = konsola jeszcze niewdrożona; panel powie to wprost
    | zamiast pokazywać pustą ramkę.
    */

    'console_proxy_url' => env('VIRTHUB_CONSOLE_PROXY_URL'),

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

];
