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
    | Licencja i addony
    |--------------------------------------------------------------------------
    | Serwer licencji wydaje tokeny licencji i paczki addonów podpisane kluczem
    | Ed25519. Panel zna tylko klucz PUBLICZNY (base64) — tokenu ani paczki nie
    | da się podrobić bez klucza prywatnego, który jest wyłącznie na serwerze
    | licencji. Bez ustawionego klucza panel działa normalnie, tylko bez addonów.
    */

    'license' => [
        'server' => rtrim((string) env('VIRTHUB_LICENSE_SERVER', ''), '/'),
        'public_key' => env('VIRTHUB_LICENSE_PUBLIC_KEY', ''),
        // Tyle dni panel ufa ostatniemu tokenowi, gdy serwer licencji nie odpowiada.
        'grace_days' => (int) env('VIRTHUB_LICENSE_GRACE_DAYS', 7),
    ],

    // Zainstalowane addony (poza kodem panelu — przetrwają aktualizację).
    'addons_path' => env('VIRTHUB_ADDONS_PATH', storage_path('app/addons')),
    // Tylko środowisko lokalne: rozpakowany addon w trakcie tworzenia, bez licencji.
    'addon_dev_path' => env('VIRTHUB_ADDON_DEV_PATH'),

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
    | Szablony budowane na węzłach KVM (Packer)
    |--------------------------------------------------------------------------
    |
    | Windows Server budowany z ISO na każdym węźle KVM: pliki instalacji
    | (Autounattend, sysprep, cloudbase-init) z publicznego repozytorium
    | VirtFusion, przepis Packera z agenta. ISO retail/SPLA podaje
    | administrator; wersje ewaluacyjne pobierają się od Microsoftu. Klucz
    | licencji klient podaje sam po instalacji — obraz ma tylko publiczny klucz
    | KMS klienta (GVLK), potrzebny przy konwersji wersji ewaluacyjnej 2025.
    */

    'template_build_files' => env('VIRTHUB_TEMPLATE_BUILD_FILES', 'https://bitbucket.org/virtfusion-public/packer/get/master.zip'),

    'template_builds' => [
        'windows-2025' => ['name' => 'Windows Server 2025 Standard', 'edition' => '2025', 'file' => 'windows-server-2025.qcow2', 'min_disk_gb' => 30, 'disk_gb' => 20,
            'kms_key' => 'TVRH6-WHNXV-R9WG3-9XRFY-MY832',
            'eval_iso' => 'https://software-static.download.prss.microsoft.com/dbazure/888969d5-f34g-4e03-ac9d-1f9786c66749/26100.1742.240906-0331.ge_release_svc_refresh_SERVER_EVAL_x64FRE_en-us.iso',
            'eval_sha256' => '16442d1c0509bcbb25b715b1b322a15fb3ab724a42da0f384b9406ca1c124ed4'],
        'windows-2022' => ['name' => 'Windows Server 2022 Standard', 'edition' => '2022', 'file' => 'windows-server-2022.qcow2', 'min_disk_gb' => 30, 'disk_gb' => 20,
            'eval_iso' => 'https://software-download.microsoft.com/download/sg/20348.169.210806-2348.fe_release_svc_refresh_SERVER_EVAL_x64FRE_en-us.iso',
            'eval_sha256' => '4f1457c4fe14ce48c9b2324924f33ca4f0470475e6da851b39ccbf98f44e7852'],
        'windows-2019' => ['name' => 'Windows Server 2019 Standard', 'edition' => '2019', 'file' => 'windows-server-2019.qcow2', 'min_disk_gb' => 30, 'disk_gb' => 20,
            'eval_iso' => 'https://software-static.download.prss.microsoft.com/pr/download/17763.737.190906-2324.rs5_release_svc_refresh_SERVER_EVAL_x64FRE_en-us_1.iso',
            'eval_sha256' => '549bca46c055157291be6c22a3aaaed8330e78ef4382c99ee82c896426a1cee1'],
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
