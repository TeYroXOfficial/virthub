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

log()  { printf '\033[0;36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[0;32m  ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[0;33m  !\033[0m %s\n' "$*"; }
die()  { printf '\033[0;31m  ✗ %s\033[0m\n' "$*" >&2; exit 1; }

# --- kontrola środowiska ----------------------------------------------------

[ "$(id -u)" -eq 0 ] || die "Uruchom jako root (sudo bash)."

command -v apt-get >/dev/null 2>&1 \
    || die "Ten instalator obsługuje Debiana i Ubuntu. Na innych dystrybucjach użyj playbooka Ansible z infra/ansible/."

log "Sprawdzam wsparcie sprzętowe dla wirtualizacji"
if [ "$(grep -Ec '(vmx|svm)' /proc/cpuinfo)" -eq 0 ]; then
    die "Procesor nie zgłasza VT-x/AMD-V. Na tej maszynie nie da się uruchomić KVM.
     Jeśli to maszyna wirtualna, włącz zagnieżdżoną wirtualizację u dostawcy."
fi
ok "KVM dostępny"

# --- pakiety ----------------------------------------------------------------

log "Instaluję pakiety (to potrwa 1-3 minuty)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
    qemu-kvm libvirt-daemon-system libvirt-dev qemu-utils \
    python3 python3-venv python3-dev build-essential pkg-config \
    genisoimage nftables nginx openssl curl ca-certificates >/dev/null
ok "Pakiety zainstalowane"

systemctl enable --now libvirtd >/dev/null 2>&1
ok "libvirtd działa"

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

# --- kontrola końcowa -------------------------------------------------------

echo
if ip link show "$BRIDGE" >/dev/null 2>&1; then
    ok "Mostek $BRIDGE istnieje"
else
    warn "Mostek $BRIDGE NIE istnieje."
    warn "Agent działa, ale tworzenie maszyn będzie kończyć się błędem."
    warn "Skonfiguruj mostek zgodnie z adresacją dostawcy, potem: systemctl restart virthub-agent"
fi

if timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -q yes; then
    ok "Zegar zsynchronizowany"
else
    warn "Zegar nie jest zsynchronizowany przez NTP. Rozjechane zegary blokują"
    warn "komunikację z panelem. Napraw: timedatectl set-ntp true"
fi

echo
printf '\033[0;32m%s\033[0m\n' "Gotowe. Węzeł zgłosił się do panelu."
echo
echo "Co jeszcze trzeba zrobić:"
echo "  1. Skonfigurować mostek sieciowy $BRIDGE (jeśli powyżej jest ostrzeżenie)"
echo "  2. Wgrać obrazy szablonów do $DATA_DIR/templates"
echo "  3. Zaimportować pulę adresów IP w panelu"
echo "  4. Ograniczyć dostęp do portu $TLS_PORT do adresu panelu na firewallu dostawcy"
echo
echo "Status agenta:  systemctl status virthub-agent"
echo "Log agenta:     journalctl -u virthub-agent -f"
