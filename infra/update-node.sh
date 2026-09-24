#!/usr/bin/env bash
#
# Aktualizacja zarejestrowanego węzła VirtHub (KVM albo LXC).
#
#   curl -sSL https://raw.githubusercontent.com/TeYroXOfficial/virthub/main/infra/update-node.sh | sudo bash
#
# Podmienia kod agenta, odświeża usługę systemd i konfigurację nginx (obsługa
# WebSocketów konsoli). Nie rusza rejestracji węzła, sekretów, certyfikatu,
# mostka sieciowego ani maszyn. Działające maszyny nie są restartowane.
#
# Inne źródło kodu: VH_TARBALL=https://…/archive/refs/heads/gałąź.tar.gz
#
set -euo pipefail

TARBALL="${VH_TARBALL:-https://github.com/TeYroXOfficial/virthub/archive/refs/heads/main.tar.gz}"
AGENT_DIR="/opt/virthub-agent"
AGENT_USER="virthub"
NGINX_SITE="/etc/nginx/sites-available/virthub-agent"

log()  { printf '\n\033[1;36m▸ %s\033[0m\n' "$*"; }
ok()   { printf '\033[0;32m  ✓\033[0m %s\n' "$*"; }
warn() { printf '\033[0;33m  !\033[0m %s\n' "$*"; }
die()  { printf '\n\033[0;31m  ✗ %s\033[0m\n\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Uruchom jako root (sudo bash)."
[ -d "$AGENT_DIR" ] || die "Brak $AGENT_DIR — to nie jest zainstalowany węzeł VirtHub. Użyj polecenia instalacyjnego z panelu."

# --- kod agenta -------------------------------------------------------------

log "Pobieram kod agenta"
WORK="$(mktemp -d /tmp/virthub-update.XXXXXX)"
trap 'rm -rf "$WORK"' EXIT

curl -fsSL "$TARBALL" | tar -xz -C "$WORK" || die "Nie udało się pobrać $TARBALL"
SRC="$(find "$WORK" -mindepth 2 -maxdepth 2 -type d -name node-agent | head -1)"
[ -n "$SRC" ] && [ -f "$SRC/agent/main.py" ] || die "Archiwum nie zawiera katalogu node-agent/."

GROUP="$(stat -c %G "$AGENT_DIR" 2>/dev/null || echo "$AGENT_USER")"
# .venv zostaje — przebudowa środowiska trwa minuty, a zależności zmieniają się rzadko.
find "$AGENT_DIR" -mindepth 1 -maxdepth 1 ! -name .venv -exec rm -rf {} +
cp -a "$SRC/." "$AGENT_DIR/"
chown -R "$AGENT_USER":"$GROUP" "$AGENT_DIR"
ok "Kod agenta podmieniony"

if [ -x "$AGENT_DIR/.venv/bin/pip" ]; then
    "$AGENT_DIR/.venv/bin/pip" install --quiet -r "$AGENT_DIR/requirements.txt" \
        || die "Instalacja zależności Pythona nie powiodła się."
    ok "Zależności Pythona aktualne"
fi

# --- usługa -----------------------------------------------------------------

log "Odświeżam usługę"
# Drop-in (np. lxc.conf z grupą incus-admin) zostaje nietknięty.
cp "$AGENT_DIR/systemd/virthub-agent.service" /etc/systemd/system/virthub-agent.service
systemctl daemon-reload
ok "Jednostka systemd zaktualizowana"

# --- nginx: WebSockety konsoli ------------------------------------------------

log "Sprawdzam nginx"
cat > /etc/nginx/conf.d/virthub-websocket.conf <<'MAPEOF'
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}
MAPEOF

if [ -f "$NGINX_SITE" ]; then
    if ! grep -q 'connection_upgrade' "$NGINX_SITE"; then
        cp "$NGINX_SITE" "$NGINX_SITE.bak"
        # Dopisujemy nagłówki WebSocket w bloku location, przed proxy_read_timeout.
        sed -i 's|^\(\s*\)proxy_read_timeout .*;|\1proxy_http_version 1.1;\n\1proxy_set_header Upgrade $http_upgrade;\n\1proxy_set_header Connection $connection_upgrade;\n\1proxy_read_timeout 3600s;|' "$NGINX_SITE"
        ok "Dodano obsługę WebSocketów (kopia: $NGINX_SITE.bak)"
    else
        ok "Obsługa WebSocketów już jest"
    fi
    if nginx -t >/dev/null 2>&1; then
        systemctl reload nginx
        ok "nginx przeładowany"
    else
        [ -f "$NGINX_SITE.bak" ] && cp "$NGINX_SITE.bak" "$NGINX_SITE"
        nginx -t || true
        die "Konfiguracja nginx jest niepoprawna — przywrócono poprzednią wersję."
    fi
else
    warn "Brak $NGINX_SITE — agent nie jest wystawiony przez nginx; konsola wymaga TLS przez nginx."
fi

# --- restart ------------------------------------------------------------------

log "Restartuję agenta"
systemctl restart virthub-agent
sleep 2
if curl -fsS "http://127.0.0.1:8899/ping" >/dev/null 2>&1; then
    ok "Agent działa"
else
    journalctl -u virthub-agent -n 30 --no-pager
    die "Agent nie wstał po aktualizacji. Log powyżej."
fi

FORWARD="$(cat /proc/sys/net/ipv4/ip_forward 2>/dev/null || echo 0)"
[ "$FORWARD" = "1" ] && ok "Przekazywanie IPv4 włączone (NAT)" \
    || warn "Przekazywanie IPv4 jest wyłączone — maszyny za NAT-em nie wyjdą w świat."

echo
printf '\033[0;32m  Węzeł zaktualizowany.\033[0m\n\n'
