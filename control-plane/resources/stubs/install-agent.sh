#!/usr/bin/env bash
#
# Instalator node agenta VirtHub.
# Wygenerowany dla węzła "__HYPERVISOR_NAME__" przez __PANEL_URL__
#
# Skrypt jest jednorazowy — bilet rejestracyjny wygasa po godzinie i unieważnia
# się przy pierwszym użyciu.

set -Eeuo pipefail

# Bez tej pułapki `set -e` przerywa instalację po cichu, w połowie, i nie
# wiadomo nawet, na którym kroku.
trap 'rc=$?; printf "\n\033[0;31m  ✗ Instalator przerwał się w linii %s (kod %s):\033[0m\n    %s\n\n" "$LINENO" "$rc" "$BASH_COMMAND" >&2' ERR

PANEL_URL="__PANEL_URL__"
TOKEN="__TOKEN__"
TLS_PORT="__TLS_PORT__"

AGENT_DIR="/opt/virthub-agent"
DATA_DIR="/var/lib/virthub"
CONFIG_DIR="/etc/virthub-agent"
AGENT_USER="virthub"
BRIDGE="${VH_BRIDGE:-br0}"
SETUP_BRIDGE="${VH_SETUP_BRIDGE:-1}"

log()  { printf '\n\033[0;36m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
ok()   { printf '\033[0;32m  ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[0;33m  !\033[0m %s\n' "$*"; }
die()  { printf '\n\033[0;31m  ✗ %s\033[0m\n\n' "$*" >&2; exit 1; }

# --- praca z lokalnej kopii -------------------------------------------------

# Skrypt przestawia konfigurację sieci hosta. Gdyby leciał prosto z potoku
# `curl … | bash`, zerwanie połączenia w trakcie tej operacji urwałoby go w
# połowie — bash czyta z potoku na bieżąco. Dlatego najpierw zapisujemy się
# do pliku i uruchamiamy ponownie już z dysku.
if [ "${VH_LOCAL_COPY:-0}" != "1" ]; then
    SELF="$(mktemp /tmp/virthub-install.XXXXXX.sh)"
    if curl -fsSL "$PANEL_URL/enroll/$TOKEN" -o "$SELF" && [ -s "$SELF" ]; then
        chmod +x "$SELF"
        export VH_LOCAL_COPY=1
        exec bash "$SELF" "$@"
    fi
    rm -f "$SELF"
    warn "Nie udało się pobrać lokalnej kopii instalatora — kontynuuję z potoku."
fi

# --- kontrola środowiska ----------------------------------------------------

[ "$(id -u)" -eq 0 ] || die "Uruchom jako root (sudo bash)."

command -v apt-get >/dev/null 2>&1 \
    || die "Ten instalator obsługuje Debiana i Ubuntu. Na innych dystrybucjach użyj playbooka z infra/ansible/."

# Najstarsze obsługiwane wydania: Debian 11 i Ubuntu 20.04. Ich systemowy
# Python jest za stary dla agenta — instalator dokłada wtedy osobny Python
# tylko dla agenta (scripts/ensure-python.sh), bez zmiany systemu.
OS_ID=""
OS_MAJOR=""
if [ -r /etc/os-release ]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    OS_ID="${ID:-}"
    OS_MAJOR="${VERSION_ID%%.*}"
    case "$OS_ID" in
        debian)
            if [ -n "$OS_MAJOR" ] && [ "$OS_MAJOR" -lt 11 ]; then
                die "Debian ${VERSION_ID} jest za stary. Obsługiwane: Debian 11, 12, 13."
            fi ;;
        ubuntu)
            if [ -n "$OS_MAJOR" ] && [ "$OS_MAJOR" -lt 20 ]; then
                die "Ubuntu ${VERSION_ID} jest za stare. Obsługiwane: Ubuntu 20.04, 22.04, 24.04."
            fi ;;
    esac
fi

# --- rodzaj węzła -----------------------------------------------------------

# Maszyny KVM wymagają sprzętowego wsparcia wirtualizacji. Bez niego (typowo
# VPS bez zagnieżdżonej wirtualizacji) węzeł uruchamia kontenery LXC — dzielą
# jądro z hostem i nie potrzebują VT-x. VH_VIRT=lxc wymusza kontenery także
# na maszynie, która KVM potrafi.
log "Sprawdzam wsparcie sprzętowe dla wirtualizacji"
VIRT="${VH_VIRT:-}"
if [ -z "$VIRT" ]; then
    if [ "$(grep -Ec '(vmx|svm)' /proc/cpuinfo)" -gt 0 ] && [ -e /dev/kvm ]; then
        VIRT="kvm"
    else
        VIRT="lxc"
    fi
fi

case "$VIRT" in
    kvm)
        ok "KVM dostępny — węzeł będzie uruchamiał maszyny wirtualne"
        VIRT_GROUP="libvirt"
        AGENT_DRIVER="libvirt"
        ;;
    lxc)
        if [ -z "${VH_VIRT:-}" ]; then
            warn "Procesor nie zgłasza VT-x/AMD-V — maszyny KVM są tu niemożliwe."
        fi
        ok "Węzeł będzie uruchamiał kontenery LXC (Incus)"
        VIRT_GROUP="incus-admin"
        AGENT_DRIVER="lxc"
        ;;
    *)
        die "VH_VIRT musi mieć wartość kvm albo lxc, ma: $VIRT" ;;
esac

# --- pakiety ----------------------------------------------------------------

log "Instaluję pakiety (to potrwa 1-3 minuty)"
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

# Debian 11 po końcu wsparcia: repozytorium poprawek bezpieczeństwa ma w
# indeksie pakiety, których pliki już usunięto (apt kończy się błędem 404).
# Zamiast niego — jego ostatni stan z archiwum snapshot.debian.org (te same,
# podpisane pakiety). Samo wyłączenie nie wystarcza: system z poprawkami ma
# biblioteki nowsze niż główne repozytorium i apt nie dobrałby pakietów -dev.
if [ "$OS_ID" = "debian" ] && [ "$OS_MAJOR" = "11" ]; then
    warn "Debian 11 nie dostaje już poprawek bezpieczeństwa — biorę je z archiwum snapshot.debian.org."
    for f in /etc/apt/sources.list /etc/apt/sources.list.d/*.list; do
        [ -f "$f" ] && grep -Eq '^[^#].*(security\.debian\.org|debian-security)' "$f" \
            && sed -i.virthub-bak -E '/security\.debian\.org|debian-security/ s/^([^#])/# \1/' "$f" || true
    done
    for f in /etc/apt/sources.list.d/*.sources; do
        [ -f "$f" ] && grep -q 'debian-security' "$f" && mv "$f" "$f.virthub-bak" || true
    done
    echo "deb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/20260901T000000Z bullseye-security main" \
        > /etc/apt/sources.list.d/virthub-bullseye-security.list
fi
apt-get update -qq

# Pozostałość po innym nginx (skompilowanym ręcznie albo z innego repozytorium
# i już usuniętym): /etc/nginx z dyrektywami, których nginx z repozytorium
# systemu nie zna (np. „http2 on;” z nginx 1.25+). Pakiet przy instalacji
# uruchamia usługę, ta nie przechodzi „nginx -t” i dpkg zostawia nginx w połowie
# zainstalowany. Taką konfigurację odkładamy na bok (nic nie kasujemy)
# i stawiamy domyślną z pakietu. Działający nginx zostaje nietknięty.
nginx_installed() {
    local p
    for p in nginx nginx-core nginx-light nginx-full nginx-extras; do
        dpkg-query -W -f='${db:Status-Abbrev}' "$p" 2>/dev/null | grep -q '^ii' && return 0
    done
    return 1
}
if [ -f /etc/nginx/nginx.conf ] && ! nginx_installed \
    && ! { command -v nginx >/dev/null 2>&1 && nginx -t >/dev/null 2>&1; }; then
    NGINX_BAK="/etc/nginx.virthub-bak-$(date +%Y%m%d%H%M%S)"
    mv /etc/nginx "$NGINX_BAK"
    warn "Stara konfiguracja nginx nie działa z nginx z repozytorium systemu — przeniesiona do $NGINX_BAK."
    # Pakiety nginx zostawione w połowie instalacji usuwamy do czysta, żeby
    # instalacja niżej postawiła je od nowa razem z domyślną konfiguracją.
    NGINX_PKGS=$(dpkg-query -W -f='${Package} ${db:Status-Abbrev}\n' 'nginx*' 'libnginx-mod*' 2>/dev/null \
        | awk '$2 !~ /^[up]n/ {print $1}')
    if [ -n "$NGINX_PKGS" ]; then
        # shellcheck disable=SC2086
        dpkg --purge --force-depends $NGINX_PKGS >/dev/null 2>&1 || true
    fi
fi

apt-get install -y -qq \
    python3 python3-venv python3-dev build-essential pkg-config \
    nftables nginx openssl curl ca-certificates gnupg \
    bridge-utils iproute2 >/dev/null

# Agent potrzebuje Pythona 3.10+. Gdy systemowy jest starszy, dostaje osobny
# (ensure-python.sh), a powiązania libvirt budują się z PyPI w wersji
# systemowego libvirt — pakiet python3-libvirt pasuje tylko do Pythona systemu.
SYSTEM_PYTHON_OK=1
python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 10) else 1)' 2>/dev/null || SYSTEM_PYTHON_OK=0

if [ "$VIRT" = "kvm" ]; then
    # python3-libvirt z repozytorium systemu, a nie libvirt-python z PyPI:
    # wersja z PyPI kompiluje się wobec systemowego libvirt i wywala, gdy
    # ten jest nowszy od niej. Pakiet systemowy zawsze pasuje.
    if [ "$SYSTEM_PYTHON_OK" = "1" ]; then
        LIBVIRT_PY="python3-libvirt"
    else
        LIBVIRT_PY="libvirt-dev"
    fi
    apt-get install -y -qq qemu-kvm libvirt-daemon-system qemu-utils genisoimage \
        "$LIBVIRT_PY" >/dev/null
    systemctl enable --now libvirtd >/dev/null 2>&1 || true
    ok "KVM i libvirtd zainstalowane"
else
    # Incus jest w Debianie 13 i Ubuntu 24.04. Starsze wydania go nie mają —
    # wtedy bierzemy oficjalne pakiety od opiekunów projektu (Zabbly).
    if ! apt-get install -y -qq incus btrfs-progs >/dev/null 2>&1; then
        warn "Incusa nie ma w repozytoriach systemu — dokładam repozytorium Zabbly."
        install -d -m 0755 /etc/apt/keyrings
        curl -fsSL https://pkgs.zabbly.com/key.asc -o /etc/apt/keyrings/zabbly.asc
        . /etc/os-release
        echo "deb [signed-by=/etc/apt/keyrings/zabbly.asc] https://pkgs.zabbly.com/incus/stable ${VERSION_CODENAME} main" \
            > /etc/apt/sources.list.d/zabbly-incus-stable.list
        apt-get update -qq
        apt-get install -y -qq incus btrfs-progs >/dev/null \
            || die "Nie udało się zainstalować Incusa na tym systemie."
    fi
    # Osobny zakres UID/GID dla każdego kontenera klienta (security.idmap.isolated).
    for f in /etc/subuid /etc/subgid; do
        touch "$f"
        if ! awk -F: '$1 == "root" && $3 >= 1000000000 { ok = 1 } END { exit !ok }' "$f"; then
            if grep -q '^root:' "$f"; then
                sed -i -E 's/^root:([0-9]+):[0-9]+$/root:\1:1000000000/' "$f"
            else
                echo "root:1000000:1000000000" >> "$f"
            fi
        fi
    done
    systemctl enable --now incus >/dev/null 2>&1 || true
    systemctl restart incus >/dev/null 2>&1 || true
    ok "Incus zainstalowany"
fi
ok "Pakiety zainstalowane"

# --- pula dyskowa kontenerów ------------------------------------------------

# btrfs, a nie zwykły katalog: tylko na puli z obsługą quot Incus egzekwuje
# limit dysku z pakietu. Na katalogu kontener klienta mógłby zapełnić cały
# dysk hosta i położyć wszystkie pozostałe.
if [ "$VIRT" = "lxc" ]; then
    log "Przygotowuję pulę dyskową kontenerów"

    if incus storage show default >/dev/null 2>&1; then
        ok "Pula default już istnieje — zostawiam bez zmian"
    else
        FREE_GB="$(df -BG --output=avail /var/lib | tail -1 | tr -dc '0-9')"
        # Zapas dla systemu hosta, logów i pobieranych obrazów.
        POOL_GB=$(( FREE_GB - 20 ))
        if [ "$POOL_GB" -lt 10 ]; then
            die "Na /var/lib jest tylko ${FREE_GB} GB wolnego miejsca — za mało na kontenery."
        fi

        preseed() {
            cat <<PRESEEDEOF
config: {}
networks: []
storage_pools:
- name: default
  driver: $1
  config: $2
profiles:
- name: default
  devices:
    root:
      path: /
      pool: default
      type: disk
PRESEEDEOF
        }

        # Bez sieci zarządzanej przez Incusa (networks: []): kontenery wpinamy
        # w mostek hosta, tak samo jak maszyny KVM, z adresami z puli panelu.
        if preseed btrfs "{size: ${POOL_GB}GiB}" | incus admin init --preseed >/dev/null 2>&1; then
            ok "Pula btrfs ${POOL_GB} GB (limity dysku egzekwowane)"
        elif preseed dir "{}" | incus admin init --preseed >/dev/null 2>&1; then
            warn "btrfs niedostępny — pula na zwykłym katalogu."
            warn "Limit dysku z pakietu NIE jest egzekwowany: kontener może zająć cały dysk hosta."
        else
            die "Nie udało się zainicjować Incusa. Sprawdź: journalctl -u incus"
        fi
    fi
fi

# --- mostek sieciowy --------------------------------------------------------

# Maszyny klientów wpinają się w mostek spięty z fizycznym interfejsem hosta.
# Bez niego agent wstanie, ale każde tworzenie maszyny skończy się błędem.
#
# Zmiana konfiguracji sieci na zdalnym serwerze potrafi odciąć dostęp, więc
# przed zastosowaniem uzbrajamy automatyczne wycofanie: jeśli w ciągu trzech
# minut nie potwierdzimy, że łączność działa, stara konfiguracja wraca sama.
setup_bridge() {
    if ip link show "$BRIDGE" >/dev/null 2>&1; then
        ok "Mostek $BRIDGE już istnieje — zostawiam bez zmian"
        return 0
    fi

    if [ "$SETUP_BRIDGE" != "1" ]; then
        warn "Pominięto konfigurację mostka (VH_SETUP_BRIDGE=0)."
        return 1
    fi

    local iface gw addr mac
    iface="$(ip -4 route show default 2>/dev/null | awk '{print $5; exit}')"
    gw="$(ip -4 route show default 2>/dev/null | awk '{print $3; exit}')"
    addr="$(ip -4 -o addr show dev "$iface" scope global 2>/dev/null | awk '{print $4; exit}')"
    # Mostek przejmuje adres MAC fizycznej karty. Domyślnie dostałby nowy,
    # wygenerowany — a dostawcy filtrujący MAC-i (Hetzner, OVH i inni) po
    # prostu odcięliby wtedy serwer od sieci.
    mac="$(cat "/sys/class/net/$iface/address" 2>/dev/null)"

    if [ -z "$iface" ] || [ -z "$addr" ] || [ -z "$gw" ] || [ -z "$mac" ]; then
        warn "Nie udało się odczytać konfiguracji sieci — mostek trzeba zrobić ręcznie."
        return 1
    fi

    # Układy nietypowe (VLAN-y, bondy, istniejące mostki) zostawiamy człowiekowi.
    # Automat, który ich nie rozumie, zrobi więcej szkody niż pożytku.
    case "$iface" in
        *.*|bond*|br*|virbr*)
            warn "Interfejs $iface wygląda na nietypowy (VLAN/bond/mostek)."
            warn "Mostek skonfiguruj ręcznie, żeby nie zepsuć istniejącego układu."
            return 1 ;;
    esac

    log "Konfiguruję mostek $BRIDGE na interfejsie $iface ($addr, brama $gw)"

    local backup="/root/virthub-net-backup-$(date +%s)"
    mkdir -p "$backup"

    local stack=""
    if [ -d /etc/netplan ] && command -v netplan >/dev/null 2>&1; then
        stack="netplan"
        cp -a /etc/netplan/. "$backup/" 2>/dev/null || true
    elif [ -f /etc/network/interfaces ]; then
        stack="ifupdown"
        cp -a /etc/network/interfaces "$backup/interfaces"
        cp -a /etc/network/interfaces.d "$backup/interfaces.d" 2>/dev/null || true
    else
        warn "Nie rozpoznaję sposobu konfiguracji sieci na tym systemie."
        return 1
    fi

    # Uzbrajamy wycofanie ZANIM cokolwiek zmienimy.
    cat > /usr/local/sbin/virthub-net-revert <<REVERTEOF
#!/bin/sh
# Przywraca konfigurację sieci sprzed instalacji agenta VirtHub.
set -e
if [ "$stack" = "netplan" ]; then
    rm -f /etc/netplan/*.yaml /etc/netplan/*.yml
    cp -a "$backup/." /etc/netplan/ 2>/dev/null || true
    netplan apply || true
else
    cp -a "$backup/interfaces" /etc/network/interfaces
    [ -d "$backup/interfaces.d" ] && cp -a "$backup/interfaces.d/." /etc/network/interfaces.d/ || true
    ifdown --force $BRIDGE 2>/dev/null || true
    systemctl restart networking || true
fi
logger -t virthub "Przywrocono konfiguracje sieci sprzed instalacji agenta."
REVERTEOF
    chmod +x /usr/local/sbin/virthub-net-revert

    systemctl stop virthub-net-revert.timer >/dev/null 2>&1 || true
    systemd-run --quiet --on-active=180 --unit=virthub-net-revert \
        /usr/local/sbin/virthub-net-revert >/dev/null 2>&1 \
        || { warn "Nie udało się uzbroić automatycznego wycofania — przerywam zmianę sieci."; return 1; }

    ok "Uzbrojono automatyczne wycofanie (3 minuty)"

    if [ "$stack" = "netplan" ]; then
        rm -f /etc/netplan/*.yaml /etc/netplan/*.yml
        cat > /etc/netplan/01-virthub.yaml <<NETPLANEOF
network:
  version: 2
  renderer: networkd
  ethernets:
    $iface:
      dhcp4: false
      dhcp6: false
  bridges:
    $BRIDGE:
      interfaces: [$iface]
      macaddress: $mac
      addresses: [$addr]
      routes:
        - to: default
          via: $gw
          on-link: true
      nameservers:
        addresses: [1.1.1.1, 9.9.9.9]
      parameters:
        stp: false
        forward-delay: 0
NETPLANEOF
        chmod 600 /etc/netplan/01-virthub.yaml
        netplan apply >/dev/null 2>&1 || true
    else
        # Stara definicja interfejsu musi zniknąć — zostawiona obok mostka
        # oznacza, że ten sam adres jest konfigurowany dwa razy.
        python3 - "$iface" <<'PYEOF'
import glob, re, sys

iface = sys.argv[1]
pattern = re.compile(rf'^\s*(auto|allow-hotplug|iface)\s+{re.escape(iface)}\b')

for path in ['/etc/network/interfaces'] + glob.glob('/etc/network/interfaces.d/*'):
    try:
        lines = open(path).read().splitlines()
    except (OSError, UnicodeDecodeError):
        continue

    out, skipping = [], False
    for line in lines:
        if pattern.match(line):
            skipping = line.strip().startswith('iface')
            out.append('# [virthub] ' + line)
            continue
        # Wcięte linie należą do poprzedniej strofy iface.
        if skipping and line[:1] in (' ', '\t') and line.strip():
            out.append('# [virthub] ' + line)
            continue
        skipping = False
        out.append(line)

    open(path, 'w').write('\n'.join(out) + '\n')
PYEOF

        local netmask
        netmask="$(python3 -c "import ipaddress; print(ipaddress.ip_network('$addr', strict=False).netmask)")"

        cat >> /etc/network/interfaces <<IFUPEOF

# Dodane przez instalator VirtHub
auto $BRIDGE
iface $BRIDGE inet static
    address ${addr%/*}
    netmask $netmask
    gateway $gw
    bridge_ports $iface
    bridge_hw $mac
    bridge_stp off
    bridge_fd 0
    dns-nameservers 1.1.1.1 9.9.9.9
IFUPEOF
        systemctl restart networking >/dev/null 2>&1 || true
    fi

    # cloud-init przy następnym starcie odtworzyłby własną konfigurację sieci
    # i skasował mostek. Wyłączamy mu tę odpowiedzialność.
    if [ -d /etc/cloud ]; then
        echo 'network: {config: disabled}' > /etc/cloud/cloud.cfg.d/99-virthub-disable-network.cfg
    fi

    sleep 8

    # Łączność sprawdzamy WYŁĄCZNIE celami poza tą maszyną. Zapytanie do panelu
    # nie nadaje się: gdy panel stoi na tym samym serwerze, odpowiedź przyszłaby
    # przez pętlę lokalną nawet przy całkowicie zepsutej sieci zewnętrznej —
    # wycofanie zostałoby anulowane, a serwer odcięty.
    reachable_outside() {
        ping -c1 -W3 "$gw" >/dev/null 2>&1 && return 0
        ping -c1 -W3 1.1.1.1 >/dev/null 2>&1 && return 0
        # Niektórzy dostawcy blokują ICMP — wtedy próba po HTTPS.
        curl -fsS --max-time 8 -o /dev/null https://1.1.1.1 2>/dev/null && return 0
        return 1
    }

    if ip link show "$BRIDGE" >/dev/null 2>&1 && reachable_outside; then
        systemctl stop virthub-net-revert.timer >/dev/null 2>&1 || true
        ok "Mostek $BRIDGE działa, łączność zachowana"
        printf '    kopia poprzedniej konfiguracji: %s\n' "$backup"
        return 0
    fi

    warn "Po zmianie sieci nie ma łączności — pozwalam wycofać konfigurację."
    warn "Serwer wróci do poprzednich ustawień w ciągu 3 minut."
    return 1
}

BRIDGE_READY=0
setup_bridge && BRIDGE_READY=1

# --- konto i katalogi -------------------------------------------------------

if ! id "$AGENT_USER" >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin --groups "$VIRT_GROUP" "$AGENT_USER"
else
    usermod -aG "$VIRT_GROUP" "$AGENT_USER"
fi

install -d -o "$AGENT_USER" -g "$VIRT_GROUP" -m 0755 "$AGENT_DIR"
install -d -o "$AGENT_USER" -g "$VIRT_GROUP" -m 0750 "$DATA_DIR" "$DATA_DIR/images" "$DATA_DIR/templates"
install -d -o "$AGENT_USER" -g "$VIRT_GROUP" -m 0700 "$DATA_DIR/seeds"
install -d -o root -g "$AGENT_USER" -m 0750 "$CONFIG_DIR"
install -d -o "$AGENT_USER" -g "$VIRT_GROUP" -m 0750 /var/log/virthub
ok "Katalogi gotowe"

# --- kod agenta -------------------------------------------------------------

log "Pobieram agenta z panelu"
curl -fsSL "$PANEL_URL/enroll/$TOKEN/agent.tar.gz" -o /tmp/virthub-agent.tar.gz \
    || die "Nie udało się pobrać agenta. Sprawdź, czy panel jest osiągalny i czy bilet nie wygasł."
# Rozpakowujemy do katalogu tymczasowego i szukamy requirements.txt, zamiast
# zakładać układ archiwum. Paczka z panelu ma pliki w korzeniu, archiwum z
# GitHuba — w jednym katalogu nadrzędnym. Ślepe --strip-components ucinało
# w pierwszym przypadku requirements.txt i spłaszczało katalog agent/.
EXTRACT_DIR="$(mktemp -d /tmp/virthub-agent.XXXXXX)"
tar -xzf /tmp/virthub-agent.tar.gz -C "$EXTRACT_DIR"
rm -f /tmp/virthub-agent.tar.gz

AGENT_SRC="$EXTRACT_DIR"
if [ ! -f "$AGENT_SRC/requirements.txt" ]; then
    NESTED="$(find "$EXTRACT_DIR" -mindepth 2 -maxdepth 2 -name requirements.txt 2>/dev/null | head -1)" || true
    [ -n "$NESTED" ] && AGENT_SRC="$(dirname "$NESTED")"
fi
[ -f "$AGENT_SRC/requirements.txt" ] && [ -f "$AGENT_SRC/agent/main.py" ] \
    || die "Paczka agenta z panelu jest niekompletna (brak requirements.txt albo agent/main.py).
     Zaktualizuj panel i wygeneruj nowe polecenie instalacyjne."

# Pozostałości po wcześniejszym, nieudanym przebiegu nie mogą zostać obok
# nowego kodu. Środowisko Pythona zostawiamy — przebuduje się niżej.
find "$AGENT_DIR" -mindepth 1 -maxdepth 1 ! -name .venv -exec rm -rf {} +
cp -a "$AGENT_SRC/." "$AGENT_DIR/"
rm -rf "$EXTRACT_DIR"
chown -R "$AGENT_USER":"$VIRT_GROUP" "$AGENT_DIR"
ok "Kod agenta rozpakowany"

log "Instaluję zależności Pythona (to potrwa 1-2 minuty)"
# --clear: środowisko po nieudanym przebiegu mogło zostać w połowie budowy.
# Węzeł KVM widzi pakiety systemowe, żeby agent mógł zaimportować
# python3-libvirt; pakiety z requirements.txt i tak mają pierwszeństwo.
PYTHON="$(bash "$AGENT_DIR/scripts/ensure-python.sh")" \
    || die "Nie udało się przygotować Pythona 3.10+ dla agenta (log powyżej)."
if [ "$VIRT" = "kvm" ] && [ "$SYSTEM_PYTHON_OK" = "1" ]; then
    "$PYTHON" -m venv --clear --system-site-packages "$AGENT_DIR/.venv"
else
    "$PYTHON" -m venv --clear "$AGENT_DIR/.venv"
fi
"$AGENT_DIR/.venv/bin/pip" install --quiet --upgrade pip
"$AGENT_DIR/.venv/bin/pip" install --quiet -r "$AGENT_DIR/requirements.txt" \
    || die "Instalacja zależności Pythona nie powiodła się (log powyżej)."
if [ "$VIRT" = "kvm" ] && [ "$SYSTEM_PYTHON_OK" = "0" ]; then
    # Powiązania w wersji równej systemowemu libvirt — wtedy zawsze się kompilują.
    LIBVIRT_VERSION="$(pkg-config --modversion libvirt)"
    "$AGENT_DIR/.venv/bin/pip" install --quiet "libvirt-python==${LIBVIRT_VERSION}" \
        || die "Nie udało się zbudować powiązań libvirt ${LIBVIRT_VERSION} dla agenta."
fi

if [ "$VIRT" = "kvm" ]; then
    "$AGENT_DIR/.venv/bin/python" -c "import libvirt" 2>/dev/null \
        || die "Agent nie widzi powiązań libvirt. Sprawdź: apt install python3-libvirt"
fi
chown -R "$AGENT_USER":"$VIRT_GROUP" "$AGENT_DIR/.venv"
ok "Zależności Pythona zainstalowane"

# --- certyfikat -------------------------------------------------------------

# Adres bierzemy z panelu, a nie z lokalnej konfiguracji: serwer za NAT-em nie
# zna swojego publicznego IP, a certyfikat musi pasować do adresu, pod który
# panel będzie się łączył.
log "Pytam panel o widoczny adres tego węzła"
PUBLIC_IP="$(curl -fsSL "$PANEL_URL/enroll/$TOKEN/whoami" 2>/dev/null \
    | python3 -c 'import sys,json; print(json.load(sys.stdin)["ip"])' 2>/dev/null)" || true
[ -n "$PUBLIC_IP" ] || die "Panel nie zwrócił adresu tego serwera. Sprawdź, czy $PANEL_URL odpowiada z tej maszyny."
ok "Panel widzi ten węzeł jako $PUBLIC_IP"

log "Generuję certyfikat TLS"
install -d -m 0700 "$CONFIG_DIR/tls"
openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
    -keyout "$CONFIG_DIR/tls/agent.key" \
    -out "$CONFIG_DIR/tls/agent.crt" \
    -subj "/CN=$PUBLIC_IP" \
    -addext "subjectAltName=IP:$PUBLIC_IP" >/dev/null 2>&1
chmod 600 "$CONFIG_DIR/tls/agent.key"
chown root:www-data "$CONFIG_DIR/tls/agent.key" 2>/dev/null || true
ok "Certyfikat wygenerowany (panel przypnie go do tego węzła)"

# --- zgłoszenie do panelu ---------------------------------------------------

CPU_CORES="$(nproc)"
RAM_MB="$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)"
DISK_GB="$(df -BG --output=size "$DATA_DIR" | tail -1 | tr -dc '0-9')"

log "Melduję się w panelu (${CPU_CORES} rdzeni, ${RAM_MB} MB RAM, ${DISK_GB} GB dysku)"

RESPONSE="$(python3 - "$PANEL_URL" "$TOKEN" "$(hostname -f 2>/dev/null || hostname)" \
                     "$CPU_CORES" "$RAM_MB" "$DISK_GB" "$CONFIG_DIR/tls/agent.crt" "$VIRT" <<'PYEOF'
import json, sys, urllib.request, urllib.error

panel, token, hostname, cores, ram, disk, cert_path, virtualization = sys.argv[1:9]

payload = json.dumps({
    "hostname": hostname,
    "cpu_cores": int(cores),
    "ram_mb": int(ram),
    "disk_gb": int(disk),
    "tls_cert": open(cert_path).read(),
    "virtualization": virtualization,
}).encode()

request = urllib.request.Request(
    f"{panel}/enroll/{token}/complete",
    data=payload,
    headers={"Content-Type": "application/json", "Accept": "application/json"},
)

try:
    with urllib.request.urlopen(request, timeout=30) as response:
        print(response.read().decode())
except urllib.error.HTTPError as exc:
    sys.stderr.write(f"Panel odrzucil zgloszenie (HTTP {exc.code}): {exc.read().decode()[:300]}\n")
    sys.exit(1)
PYEOF
)" || die "Rejestracja w panelu nie powiodła się."

AGENT_TOKEN="$(printf '%s' "$RESPONSE" | python3 -c 'import sys,json; print(json.load(sys.stdin)["agent_token"])' 2>/dev/null)" || true
CALLBACK_SECRET="$(printf '%s' "$RESPONSE" | python3 -c 'import sys,json; print(json.load(sys.stdin)["callback_secret"])' 2>/dev/null)" || true
[ -n "$AGENT_TOKEN" ] && [ -n "$CALLBACK_SECRET" ] \
    || die "Panel odpowiedział, ale bez tokenów agenta. Odpowiedź: ${RESPONSE:0:300}"
ok "Węzeł zarejestrowany w panelu"

# --- konfiguracja agenta ----------------------------------------------------

cat > "$CONFIG_DIR/agent.env" <<EOF
# Wygenerowane przez instalator VirtHub. Zawiera sekrety wspoldzielone z panelem.
VH_AGENT_TOKEN=$AGENT_TOKEN
VH_CALLBACK_SECRET=$CALLBACK_SECRET
VH_CONTROL_PLANE_URL=$PANEL_URL

VH_AGENT_DRIVER=$AGENT_DRIVER
VH_LIBVIRT_URI=qemu:///system

VH_IMAGE_DIR=$DATA_DIR/images
VH_TEMPLATE_DIR=$DATA_DIR/templates
VH_SEED_DIR=$DATA_DIR/seeds
VH_STATE_DB=$DATA_DIR/agent-state.sqlite3

VH_BRIDGE=$BRIDGE
VH_NFT_TABLE=virthub
VH_VNC_LISTEN=127.0.0.1
VH_MAX_CLOCK_SKEW=300
EOF
chmod 640 "$CONFIG_DIR/agent.env"
chown root:"$AGENT_USER" "$CONFIG_DIR/agent.env"
ok "Konfiguracja zapisana"

# --- usługa -----------------------------------------------------------------

cp "$AGENT_DIR/systemd/virthub-agent.service" /etc/systemd/system/virthub-agent.service

# Węzeł kontenerów: agent działa w grupie incus-admin zamiast libvirt, a klient
# incus trzyma swoją konfigurację (listę serwerów obrazów) w katalogu agenta —
# konto usługi nie ma katalogu domowego.
rm -rf /etc/systemd/system/virthub-agent.service.d
if [ "$VIRT" = "lxc" ]; then
    install -d -o "$AGENT_USER" -g "$VIRT_GROUP" -m 0700 "$DATA_DIR/incus-client"
    install -d -m 0755 /etc/systemd/system/virthub-agent.service.d
    cat > /etc/systemd/system/virthub-agent.service.d/lxc.conf <<UNITEOF
[Service]
Group=$VIRT_GROUP
Environment=INCUS_CONF=$DATA_DIR/incus-client
UNITEOF
fi
# Zapora maszyn śledzi połączenia w rodzinie bridge (odpowiedzi na ruch
# wychodzący przy domyślnej blokadzie) — wymaga modułu nf_conntrack_bridge.
# Bez niego agent przechodzi w tryb bezstanowy, więc brak modułu nie jest błędem.
echo nf_conntrack_bridge > /etc/modules-load.d/virthub.conf
modprobe nf_conntrack_bridge 2>/dev/null || true

# DNS hosta po zmianie sieci — serwery DNS ze strofy starego interfejsu
# mogły zniknąć; skrypt naprawia tylko wolny/niedziałający DNS.
if [ -f "$AGENT_DIR/scripts/fix-dns.sh" ]; then
    bash "$AGENT_DIR/scripts/fix-dns.sh" || warn "DNS hosta działa wolno — sprawdź /etc/resolv.conf."
fi

# Węzły KVM: budowa szablonów Windows (grupa kvm dla konta agenta).
if [ -f "$AGENT_DIR/scripts/setup-builder.sh" ]; then
    bash "$AGENT_DIR/scripts/setup-builder.sh" || warn "Nie udało się przygotować budowy szablonów."
fi

# Aplikacje (serwery gier, boty): Docker tylko na życzenie — VH_APPS=1.
if [ -f "$AGENT_DIR/scripts/setup-apps.sh" ]; then
    AGENT_USER="$AGENT_USER" bash "$AGENT_DIR/scripts/setup-apps.sh" || warn "Nie udało się zainstalować Dockera dla aplikacji."
fi

# Aktualizacje zlecane z panelu (Administracja → Aktualizacje).
if [ -f "$AGENT_DIR/scripts/install-updater.sh" ]; then
    bash "$AGENT_DIR/scripts/install-updater.sh"
fi
systemctl daemon-reload
systemctl enable --now virthub-agent >/dev/null 2>&1
sleep 2

if ! curl -fsS "http://127.0.0.1:8899/ping" >/dev/null 2>&1; then
    journalctl -u virthub-agent -n 30 --no-pager
    die "Agent nie wstał. Log powyżej."
fi
ok "Agent działa na 127.0.0.1:8899"

# --- nginx: TLS dla panelu --------------------------------------------------

# Agent nasluchuje wylacznie na loopbacku. Ruch z panelu wchodzi tedy, zeby
# hasla root maszyn i klucze SSH nie szly przez siec otwartym tekstem.
# Nagłówek Connection dla WebSocketów konsoli. Ten sam plik zapisuje
# instalator panelu — na serwerze z panelem i węzłem mapa jest jedna.
cat > /etc/nginx/conf.d/virthub-websocket.conf <<'MAPEOF'
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}
MAPEOF

cat > /etc/nginx/sites-available/virthub-agent <<EOF
server {
    listen $TLS_PORT ssl;
    server_name $PUBLIC_IP;

    ssl_certificate     $CONFIG_DIR/tls/agent.crt;
    ssl_certificate_key $CONFIG_DIR/tls/agent.key;
    ssl_protocols TLSv1.2 TLSv1.3;

    # Pliki aplikacji z panelu (kawałki po kilka MB), paczki treści.
    client_max_body_size 100m;

    location / {
        proxy_pass http://127.0.0.1:8899;
        proxy_set_header Host \$host;
        # Konsola maszyn idzie WebSocketem przez ten sam port.
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_read_timeout 3600s;
    }
}
EOF

ln -sf /etc/nginx/sites-available/virthub-agent /etc/nginx/sites-enabled/virthub-agent
if ! NGINX_TEST=$(nginx -t 2>&1); then
    echo "$NGINX_TEST" >&2
    die "Konfiguracja nginx jest niepoprawna (szczegóły powyżej)."
fi
systemctl enable --now nginx >/dev/null 2>&1
systemctl reload nginx
ok "TLS nasłuchuje na porcie $TLS_PORT"

# --- obrazy szablonów -------------------------------------------------------

# Bez choćby jednego obrazu nie da się utworzyć maszyny, a pobranie go to
# jedyna rzecz, o której i tak trzeba by pamiętać po instalacji.
if [ "$VIRT" = "lxc" ]; then
    # Szablony kontenerów pobiera panel: po rejestracji węzła zleca pobranie
    # wszystkich aktywnych szablonów LXC, a brakujący obraz węzeł ściąga sam
    # przy pierwszym zamówieniu.
    ok "Szablony kontenerów zostaną pobrane automatycznie na zlecenie panelu"
elif [ -z "$(ls -A "$DATA_DIR/templates" 2>/dev/null)" ]; then
    log "Pobieram obraz Ubuntu 24.04 (ok. 600 MB)"
    if curl -fsSL --max-time 900 \
        "https://cloud-images.ubuntu.com/releases/24.04/release/ubuntu-24.04-server-cloudimg-amd64.img" \
        -o "$DATA_DIR/templates/ubuntu-24.04.qcow2"; then
        chown "$AGENT_USER":"$VIRT_GROUP" "$DATA_DIR/templates/ubuntu-24.04.qcow2"
        ok "Obraz ubuntu-24.04.qcow2 gotowy"
    else
        rm -f "$DATA_DIR/templates/ubuntu-24.04.qcow2"
        warn "Nie udało się pobrać obrazu. Wgraj go później do $DATA_DIR/templates."
    fi
fi

# --- kontrola końcowa -------------------------------------------------------

echo
if [ "$BRIDGE_READY" -eq 1 ]; then
    ok "Mostek $BRIDGE gotowy"
else
    warn "Mostek $BRIDGE nie został skonfigurowany."
    warn "Agent działa, ale tworzenie maszyn będzie kończyć się błędem."
    warn "Skonfiguruj mostek ręcznie, potem: systemctl restart virthub-agent"
fi

if timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -q yes; then
    ok "Zegar zsynchronizowany"
else
    warn "Zegar nie jest zsynchronizowany. Rozjechane zegary blokują komunikację"
    warn "z panelem. Naprawa: timedatectl set-ntp true"
    timedatectl set-ntp true >/dev/null 2>&1 || true
fi

echo
printf '\033[0;32m━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\033[0m\n'
if [ "$VIRT" = "lxc" ]; then
    printf '\033[0;32m  Węzeł kontenerów LXC zarejestrowany i gotowy\033[0m\n'
else
    printf '\033[0;32m  Węzeł KVM zarejestrowany i gotowy\033[0m\n'
fi
printf '\033[0;32m━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\033[0m\n'
echo
echo "  W panelu zobaczysz go jako online w ciągu minuty."
echo "  Zostaje tylko zaimportować pulę adresów IP: Administracja → Adresy IP."
echo
echo "  Status agenta:  systemctl status virthub-agent"
echo "  Log agenta:     journalctl -u virthub-agent -f"
echo
