#!/usr/bin/env bash
#
# Instalator node agenta VirtHub.
# Wygenerowany dla węzła "__HYPERVISOR_NAME__" przez __PANEL_URL__
#
# Skrypt jest jednorazowy — bilet rejestracyjny wygasa po godzinie i unieważnia
# się przy pierwszym użyciu.

set -euo pipefail

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

log "Sprawdzam wsparcie sprzętowe dla wirtualizacji"
if [ "$(grep -Ec '(vmx|svm)' /proc/cpuinfo)" -eq 0 ]; then
    die "Procesor nie zgłasza VT-x/AMD-V. Na tej maszynie nie da się uruchomić KVM.
     Jeśli to maszyna wirtualna, włącz zagnieżdżoną wirtualizację u dostawcy."
fi
ok "KVM dostępny"

# --- pakiety ----------------------------------------------------------------

log "Instaluję pakiety (to potrwa 1-3 minuty)"
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
apt-get update -qq
apt-get install -y -qq \
    qemu-kvm libvirt-daemon-system libvirt-dev qemu-utils \
    python3 python3-venv python3-dev build-essential pkg-config \
    genisoimage nftables nginx openssl curl ca-certificates \
    bridge-utils iproute2 >/dev/null
ok "Pakiety zainstalowane"

systemctl enable --now libvirtd >/dev/null 2>&1 || true
ok "libvirtd działa"

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

    local iface gw addr
    iface="$(ip -4 route show default 2>/dev/null | awk '{print $5; exit}')"
    gw="$(ip -4 route show default 2>/dev/null | awk '{print $3; exit}')"
    addr="$(ip -4 -o addr show dev "$iface" scope global 2>/dev/null | awk '{print $4; exit}')"

    if [ -z "$iface" ] || [ -z "$addr" ] || [ -z "$gw" ]; then
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
    bridge_stp off
    bridge_fd 0
IFUPEOF
        systemctl restart networking >/dev/null 2>&1 || true
    fi

    # cloud-init przy następnym starcie odtworzyłby własną konfigurację sieci
    # i skasował mostek. Wyłączamy mu tę odpowiedzialność.
    if [ -d /etc/cloud ]; then
        echo 'network: {config: disabled}' > /etc/cloud/cloud.cfg.d/99-virthub-disable-network.cfg
    fi

    sleep 8

    # Sprawdzamy to, co faktycznie ma działać: łączność z panelem.
    if ip link show "$BRIDGE" >/dev/null 2>&1 \
       && { curl -fsS --max-time 10 "$PANEL_URL/up" >/dev/null 2>&1 \
            || ping -c1 -W3 "$gw" >/dev/null 2>&1; }; then
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
    useradd --system --no-create-home --shell /usr/sbin/nologin --groups libvirt "$AGENT_USER"
fi

install -d -o "$AGENT_USER" -g libvirt -m 0755 "$AGENT_DIR"
install -d -o "$AGENT_USER" -g libvirt -m 0750 "$DATA_DIR" "$DATA_DIR/images" "$DATA_DIR/templates"
install -d -o "$AGENT_USER" -g libvirt -m 0700 "$DATA_DIR/seeds"
install -d -o root -g "$AGENT_USER" -m 0750 "$CONFIG_DIR"
install -d -o "$AGENT_USER" -g libvirt -m 0750 /var/log/virthub
ok "Katalogi gotowe"

# --- kod agenta -------------------------------------------------------------

log "Pobieram agenta z panelu"
curl -fsSL "$PANEL_URL/enroll/$TOKEN/agent.tar.gz" -o /tmp/virthub-agent.tar.gz \
    || die "Nie udało się pobrać agenta. Sprawdź, czy panel jest osiągalny i czy bilet nie wygasł."
tar -xzf /tmp/virthub-agent.tar.gz -C "$AGENT_DIR" --strip-components=1
rm -f /tmp/virthub-agent.tar.gz
chown -R "$AGENT_USER":libvirt "$AGENT_DIR"
ok "Kod agenta rozpakowany"

log "Instaluję zależności Pythona (to potrwa 1-2 minuty)"
python3 -m venv "$AGENT_DIR/.venv"
"$AGENT_DIR/.venv/bin/pip" install --quiet --upgrade pip
"$AGENT_DIR/.venv/bin/pip" install --quiet -r "$AGENT_DIR/requirements.txt" \
    || die "Instalacja zależności Pythona nie powiodła się. Sprawdź, czy libvirt-dev się zainstalował."
chown -R "$AGENT_USER":libvirt "$AGENT_DIR/.venv"
ok "Zależności Pythona zainstalowane"

# --- certyfikat -------------------------------------------------------------

# Adres bierzemy z panelu, a nie z lokalnej konfiguracji: serwer za NAT-em nie
# zna swojego publicznego IP, a certyfikat musi pasować do adresu, pod który
# panel będzie się łączył.
log "Pytam panel o widoczny adres tego węzła"
PUBLIC_IP="$(curl -fsSL "$PANEL_URL/enroll/$TOKEN/whoami" | python3 -c 'import sys,json; print(json.load(sys.stdin)["ip"])')"
[ -n "$PUBLIC_IP" ] || die "Panel nie zwrócił adresu tego serwera."
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
                     "$CPU_CORES" "$RAM_MB" "$DISK_GB" "$CONFIG_DIR/tls/agent.crt" <<'PYEOF'
import json, sys, urllib.request, urllib.error

panel, token, hostname, cores, ram, disk, cert_path = sys.argv[1:8]

payload = json.dumps({
    "hostname": hostname,
    "cpu_cores": int(cores),
    "ram_mb": int(ram),
    "disk_gb": int(disk),
    "tls_cert": open(cert_path).read(),
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

AGENT_TOKEN="$(printf '%s' "$RESPONSE" | python3 -c 'import sys,json; print(json.load(sys.stdin)["agent_token"])')"
CALLBACK_SECRET="$(printf '%s' "$RESPONSE" | python3 -c 'import sys,json; print(json.load(sys.stdin)["callback_secret"])')"
[ -n "$AGENT_TOKEN" ] || die "Panel nie zwrócił tokenu agenta."
ok "Węzeł zarejestrowany w panelu"

# --- konfiguracja agenta ----------------------------------------------------

cat > "$CONFIG_DIR/agent.env" <<EOF
# Wygenerowane przez instalator VirtHub. Zawiera sekrety wspoldzielone z panelem.
VH_AGENT_TOKEN=$AGENT_TOKEN
VH_CALLBACK_SECRET=$CALLBACK_SECRET
VH_CONTROL_PLANE_URL=$PANEL_URL

VH_AGENT_DRIVER=libvirt
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
cat > /etc/nginx/sites-available/virthub-agent <<EOF
server {
    listen $TLS_PORT ssl;
    server_name $PUBLIC_IP;

    ssl_certificate     $CONFIG_DIR/tls/agent.crt;
    ssl_certificate_key $CONFIG_DIR/tls/agent.key;
    ssl_protocols TLSv1.2 TLSv1.3;

    location / {
        proxy_pass http://127.0.0.1:8899;
        proxy_set_header Host \$host;
        proxy_read_timeout 120s;
    }
}
EOF

ln -sf /etc/nginx/sites-available/virthub-agent /etc/nginx/sites-enabled/virthub-agent
nginx -t >/dev/null 2>&1 || die "Konfiguracja nginx jest niepoprawna."
systemctl enable --now nginx >/dev/null 2>&1
systemctl reload nginx
ok "TLS nasłuchuje na porcie $TLS_PORT"

# --- obrazy szablonów -------------------------------------------------------

# Bez choćby jednego obrazu nie da się utworzyć maszyny, a pobranie go to
# jedyna rzecz, o której i tak trzeba by pamiętać po instalacji.
if [ -z "$(ls -A "$DATA_DIR/templates" 2>/dev/null)" ]; then
    log "Pobieram obraz Ubuntu 24.04 (ok. 600 MB)"
    if curl -fsSL --max-time 900 \
        "https://cloud-images.ubuntu.com/releases/24.04/release/ubuntu-24.04-server-cloudimg-amd64.img" \
        -o "$DATA_DIR/templates/ubuntu-24.04.qcow2"; then
        chown "$AGENT_USER":libvirt "$DATA_DIR/templates/ubuntu-24.04.qcow2"
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
printf '\033[0;32m  Węzeł zarejestrowany i gotowy\033[0m\n'
printf '\033[0;32m━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\033[0m\n'
echo
echo "  W panelu zobaczysz go jako online w ciągu minuty."
echo "  Zostaje tylko zaimportować pulę adresów IP: Administracja → Adresy IP."
echo
echo "  Status agenta:  systemctl status virthub-agent"
echo "  Log agenta:     journalctl -u virthub-agent -f"
echo
