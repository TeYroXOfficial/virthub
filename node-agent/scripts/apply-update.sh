#!/usr/bin/env bash
#
# Wdrożenie pobranego kodu agenta na węźle. Wywoływane przez update-node.sh
# z katalogu świeżo pobranego archiwum:  apply-update.sh <katalog node-agent> [commit]
#
# Nie rusza rejestracji węzła, sekretów, certyfikatu, mostka ani maszyn.
#
set -Eeuo pipefail

SRC="$1"
COMMIT="${2:-}"
AGENT_DIR="/opt/virthub-agent"
AGENT_USER="virthub"
NGINX_SITE="/etc/nginx/sites-available/virthub-agent"

ok()   { printf '  ✓ %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*"; }

GROUP="$(stat -c %G "$AGENT_DIR" 2>/dev/null || echo "$AGENT_USER")"

# --- kod ----------------------------------------------------------------------

# .venv zostaje — przebudowa środowiska trwa minuty, a zależności zmieniają się rzadko.
find "$AGENT_DIR" -mindepth 1 -maxdepth 1 ! -name .venv -exec rm -rf {} +
cp -a "$SRC/." "$AGENT_DIR/"
if [ -n "$COMMIT" ]; then
    printf '%s\n' "$COMMIT" > "$AGENT_DIR/VERSION"
fi
chown -R "$AGENT_USER":"$GROUP" "$AGENT_DIR"
ok "Kod agenta podmieniony${COMMIT:+ (${COMMIT:0:12})}"

if [ -x "$AGENT_DIR/.venv/bin/pip" ]; then
    "$AGENT_DIR/.venv/bin/pip" install --quiet -r "$AGENT_DIR/requirements.txt"
    ok "Zależności Pythona aktualne"
fi

# --- usługa agenta ------------------------------------------------------------

# Drop-in (np. lxc.conf z grupą incus-admin) zostaje nietknięty.
cp "$AGENT_DIR/systemd/virthub-agent.service" /etc/systemd/system/virthub-agent.service
bash "$AGENT_DIR/scripts/install-updater.sh"
systemctl daemon-reload
ok "Jednostki systemd zaktualizowane"

# --- nginx: WebSockety konsoli --------------------------------------------------

cat > /etc/nginx/conf.d/virthub-websocket.conf <<'MAPEOF'
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}
MAPEOF

if [ -f "$NGINX_SITE" ]; then
    if ! grep -q 'connection_upgrade' "$NGINX_SITE"; then
        cp "$NGINX_SITE" "$NGINX_SITE.bak"
        sed -i 's|^\(\s*\)proxy_read_timeout .*;|\1proxy_http_version 1.1;\n\1proxy_set_header Upgrade $http_upgrade;\n\1proxy_set_header Connection $connection_upgrade;\n\1proxy_read_timeout 3600s;|' "$NGINX_SITE"
        ok "nginx: dodano obsługę WebSocketów (kopia: $NGINX_SITE.bak)"
    fi
    if nginx -t >/dev/null 2>&1; then
        systemctl reload nginx
    else
        [ -f "$NGINX_SITE.bak" ] && cp "$NGINX_SITE.bak" "$NGINX_SITE"
        nginx -t || true
        echo "Konfiguracja nginx jest niepoprawna — przywrócono poprzednią wersję." >&2
        exit 1
    fi
else
    warn "Brak $NGINX_SITE — agent nie jest wystawiony przez nginx."
fi

# --- restart --------------------------------------------------------------------

systemctl restart virthub-agent
for _ in 1 2 3 4 5 6 7 8 9 10; do
    curl -fsS "http://127.0.0.1:8899/ping" >/dev/null 2>&1 && break
    sleep 1
done
if ! curl -fsS "http://127.0.0.1:8899/ping" >/dev/null 2>&1; then
    journalctl -u virthub-agent -n 30 --no-pager || true
    echo "Agent nie wstał po aktualizacji." >&2
    exit 1
fi
ok "Agent działa"

FORWARD="$(cat /proc/sys/net/ipv4/ip_forward 2>/dev/null || echo 0)"
[ "$FORWARD" = "1" ] || warn "Przekazywanie IPv4 jest wyłączone — maszyny za NAT-em nie wyjdą w świat."
