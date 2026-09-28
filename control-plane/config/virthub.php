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

    // Port SFTP aplikacji na węzłach (VH_SFTP_PORT agenta) — pokazywany klientom.
    'apps_sftp_port' => (int) env('VIRTHUB_APPS_SFTP_PORT', 2022),

    // Aplikacja, w której węzeł wykrył PteroVM/proot/QEMU, koparkę albo
    // zdalną powłokę, jest automatycznie zawieszana (false = tylko zgłoszenie).
    // Klucz API CurseForge (darmowy: console.curseforge.com) — bez niego
    // modpacki i pluginy z CurseForge są ukryte; Modrinth, FTB i Hangar działają bez klucza.
    'curseforge_api_key' => env('VIRTHUB_CURSEFORGE_API_KEY'),

    // Resolvery DNS dla zapytań do Modrinth/FTB/CurseForge/Hangar (panel pyta je
    // wprost i pamięta adresy — omija wolny DNS systemu). Pusto = DNS systemu.
    'content_dns' => env('VIRTHUB_CONTENT_DNS', '1.1.1.1,9.9.9.9'),

    'apps_abuse_suspend' => (bool) env('VIRTHUB_APPS_ABUSE_SUSPEND', true),

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
    | Katalog szablonów maszyn wirtualnych (KVM)
    |--------------------------------------------------------------------------
    |
    | Oficjalne obrazy cloud dystrybucji (qcow2 z cloud-init) — te same, na
    | których bazują gotowe szablony innych paneli. Węzeł KVM pobiera obraz
    | sam i sprawdza sumę z pliku sum kontrolnych dystrybucji.
    */

    'kvm_catalog' => [
        'ubuntu-2404' => ['name' => 'Ubuntu 24.04 LTS', 'family' => 'ubuntu', 'version' => '24.04', 'file' => 'ubuntu-24.04.qcow2', 'min_disk_gb' => 10,
            'url' => 'https://cloud-images.ubuntu.com/noble/current/noble-server-cloudimg-amd64.img',
            'checksum_url' => 'https://cloud-images.ubuntu.com/noble/current/SHA256SUMS'],
        'ubuntu-2204' => ['name' => 'Ubuntu 22.04 LTS', 'family' => 'ubuntu', 'version' => '22.04', 'file' => 'ubuntu-22.04.qcow2', 'min_disk_gb' => 10,
            'url' => 'https://cloud-images.ubuntu.com/jammy/current/jammy-server-cloudimg-amd64.img',
            'checksum_url' => 'https://cloud-images.ubuntu.com/jammy/current/SHA256SUMS'],
        'debian-13' => ['name' => 'Debian 13', 'family' => 'debian', 'version' => '13', 'file' => 'debian-13.qcow2', 'min_disk_gb' => 10,
            'url' => 'https://cloud.debian.org/images/cloud/trixie/latest/debian-13-genericcloud-amd64.qcow2',
            'checksum_url' => 'https://cloud.debian.org/images/cloud/trixie/latest/SHA512SUMS'],
        'debian-12' => ['name' => 'Debian 12', 'family' => 'debian', 'version' => '12', 'file' => 'debian-12.qcow2', 'min_disk_gb' => 10,
            'url' => 'https://cloud.debian.org/images/cloud/bookworm/latest/debian-12-genericcloud-amd64.qcow2',
            'checksum_url' => 'https://cloud.debian.org/images/cloud/bookworm/latest/SHA512SUMS'],
        'almalinux-10' => ['name' => 'AlmaLinux 10', 'family' => 'almalinux', 'version' => '10', 'file' => 'almalinux-10.qcow2', 'min_disk_gb' => 15,
            'url' => 'https://repo.almalinux.org/almalinux/10/cloud/x86_64/images/AlmaLinux-10-GenericCloud-latest.x86_64.qcow2',
            'checksum_url' => 'https://repo.almalinux.org/almalinux/10/cloud/x86_64/images/CHECKSUM'],
        'almalinux-9' => ['name' => 'AlmaLinux 9', 'family' => 'almalinux', 'version' => '9', 'file' => 'almalinux-9.qcow2', 'min_disk_gb' => 15,
            'url' => 'https://repo.almalinux.org/almalinux/9/cloud/x86_64/images/AlmaLinux-9-GenericCloud-latest.x86_64.qcow2',
            'checksum_url' => 'https://repo.almalinux.org/almalinux/9/cloud/x86_64/images/CHECKSUM'],
        'rocky-10' => ['name' => 'Rocky Linux 10', 'family' => 'rocky', 'version' => '10', 'file' => 'rocky-10.qcow2', 'min_disk_gb' => 15,
            'url' => 'https://dl.rockylinux.org/pub/rocky/10/images/x86_64/Rocky-10-GenericCloud-Base.latest.x86_64.qcow2',
            'checksum_url' => 'https://dl.rockylinux.org/pub/rocky/10/images/x86_64/Rocky-10-GenericCloud-Base.latest.x86_64.qcow2.CHECKSUM'],
        'rocky-9' => ['name' => 'Rocky Linux 9', 'family' => 'rocky', 'version' => '9', 'file' => 'rocky-9.qcow2', 'min_disk_gb' => 15,
            'url' => 'https://dl.rockylinux.org/pub/rocky/9/images/x86_64/Rocky-9-GenericCloud-Base.latest.x86_64.qcow2',
            'checksum_url' => 'https://dl.rockylinux.org/pub/rocky/9/images/x86_64/Rocky-9-GenericCloud-Base.latest.x86_64.qcow2.CHECKSUM'],
        'centos-stream-10' => ['name' => 'CentOS Stream 10', 'family' => 'centos', 'version' => '10', 'file' => 'centos-stream-10.qcow2', 'min_disk_gb' => 15,
            'url' => 'https://cloud.centos.org/centos/10-stream/x86_64/images/CentOS-Stream-GenericCloud-10-latest.x86_64.qcow2',
            'checksum_url' => 'https://cloud.centos.org/centos/10-stream/x86_64/images/CentOS-Stream-GenericCloud-10-latest.x86_64.qcow2.SHA256SUM'],
        'centos-stream-9' => ['name' => 'CentOS Stream 9', 'family' => 'centos', 'version' => '9', 'file' => 'centos-stream-9.qcow2', 'min_disk_gb' => 15,
            'url' => 'https://cloud.centos.org/centos/9-stream/x86_64/images/CentOS-Stream-GenericCloud-9-latest.x86_64.qcow2',
            'checksum_url' => 'https://cloud.centos.org/centos/9-stream/x86_64/images/CentOS-Stream-GenericCloud-9-latest.x86_64.qcow2.SHA256SUM'],
        'arch' => ['name' => 'Arch Linux', 'family' => 'arch', 'version' => 'rolling', 'file' => 'arch-linux.qcow2', 'min_disk_gb' => 10,
            'url' => 'https://geo.mirror.pkgbuild.com/images/latest/Arch-Linux-x86_64-cloudimg.qcow2',
            'checksum_url' => 'https://geo.mirror.pkgbuild.com/images/latest/Arch-Linux-x86_64-cloudimg.qcow2.SHA256'],
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

        // Ile aplikacji (serwerów gier, botów) może mieć jeden klient.
        'apps_per_customer' => (int) env('VIRTHUB_APPS_PER_CUSTOMER', 10),

        // Retencja próbek telemetrii w dniach.
        'metrics_retention_days' => (int) env('VIRTHUB_METRICS_RETENTION_DAYS', 30),
    ],

    // Co wlicza się do limitu transferu pakietu: total (obie strony), out, in.
    'traffic_counting' => env('VIRTHUB_TRAFFIC_COUNTING', 'total'),
    // Języki panelu. Teksty źródłowe są po polsku; angielskie w lang/en.json.
    'locales' => ['pl' => 'Polski', 'en' => 'English'],
    'default_locale' => env('VIRTHUB_LOCALE', 'pl'),
];
