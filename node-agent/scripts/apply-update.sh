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
    # requirements.txt bez zmian od ostatniej aktualizacji — pip nie ma nic do zrobienia.
    REQ_SUM="$(sha256sum "$AGENT_DIR/requirements.txt" | cut -d' ' -f1)"
    STAMP="/var/lib/virthub/update/requirements.sha256"
    if [ -f "$STAMP" ] && [ "$(cat "$STAMP")" = "$REQ_SUM" ] && [ "${VH_FULL_INSTALL:-0}" != "1" ]; then
        ok "Zależności Pythona bez zmian"
    else
        "$AGENT_DIR/.venv/bin/pip" install --quiet -r "$AGENT_DIR/requirements.txt"
        mkdir -p "$(dirname "$STAMP")" && printf '%s' "$REQ_SUM" > "$STAMP"
        ok "Zależności Pythona zaktualizowane"
    fi
fi

# --- usługa agenta ------------------------------------------------------------

# Drop-in (np. lxc.conf z grupą incus-admin) zostaje nietknięty.
cp "$AGENT_DIR/systemd/virthub-agent.service" /etc/systemd/system/virthub-agent.service
bash "$AGENT_DIR/scripts/install-updater.sh"
[ -f "$AGENT_DIR/scripts/install-mariadb-unit.sh" ] \
    && { bash "$AGENT_DIR/scripts/install-mariadb-unit.sh" || warn "Nie udało się zainstalować usługi MariaDB"; }
systemctl daemon-reload
ok "Jednostki systemd zaktualizowane"

# --- nginx: WebSockety konsoli --------------------------------------------------

cat > /etc/nginx/conf.d/virthub-websocket.conf <<'MAPEOF'
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}
MAPEOF

# Temperatury dysków SATA dla monitoringu w panelu (moduł jądra drivetemp).
if [ -f /etc/modules-load.d/virthub.conf ] && ! grep -q drivetemp /etc/modules-load.d/virthub.conf; then
    echo drivetemp >> /etc/modules-load.d/virthub.conf
fi
modprobe drivetemp 2>/dev/null || true

if [ -f "$NGINX_SITE" ]; then
    if ! grep -q 'connection_upgrade' "$NGINX_SITE"; then
        cp "$NGINX_SITE" "$NGINX_SITE.bak"
        sed -i 's|^\(\s*\)proxy_read_timeout .*;|\1proxy_http_version 1.1;\n\1proxy_set_header Upgrade $http_upgrade;\n\1proxy_set_header Connection $connection_upgrade;\n\1proxy_read_timeout 3600s;|' "$NGINX_SITE"
        ok "nginx: dodano obsługę WebSocketów (kopia: $NGINX_SITE.bak)"
    fi
    # Domyślny limit nginx (1 MB) odrzucał wgrywanie plików aplikacji (413).
    if ! grep -q 'client_max_body_size' "$NGINX_SITE"; then
        [ -f "$NGINX_SITE.bak" ] || cp "$NGINX_SITE" "$NGINX_SITE.bak"
        sed -i '0,/^\(\s*\)location \/ {/s//\1client_max_body_size 100m;\n\n&/' "$NGINX_SITE"
        ok "nginx: limit rozmiaru żądania 100 MB (wgrywanie plików)"
    fi
    # Adres panelu dla agenta (konto administracyjne MariaDB tylko z tego adresu).
    if ! grep -q 'X-Real-IP' "$NGINX_SITE"; then
        [ -f "$NGINX_SITE.bak" ] || cp "$NGINX_SITE" "$NGINX_SITE.bak"
        sed -i 's|^\(\s*\)proxy_set_header Host \$host;|&\n\1proxy_set_header X-Real-IP $remote_addr;|' "$NGINX_SITE"
        ok "nginx: agent dostaje adres panelu (X-Real-IP)"
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

# Zapora maszyn śledzi połączenia w rodzinie bridge (odpowiedzi na ruch
# wychodzący przy domyślnej blokadzie) — wymaga modułu nf_conntrack_bridge.
# Bez niego agent przechodzi w tryb bezstanowy, więc brak modułu nie jest błędem.
echo nf_conntrack_bridge > /etc/modules-load.d/virthub.conf
modprobe nf_conntrack_bridge 2>/dev/null || true

# Wolny DNS hosta (np. martwy serwer po zamianie eth0 na most) spowalnia
# panel, pobieranie modpacków i kontenery — skrypt naprawia go tylko wtedy.
bash "$AGENT_DIR/scripts/fix-dns.sh" || warn "DNS hosta nadal działa wolno — sprawdź /etc/resolv.conf"

# Aplikacje (serwery gier, boty): Docker instalujemy tylko z VH_APPS=1, a na
# węźle, który już go ma, pilnujemy grupy docker i katalogu aplikacji.
bash "$AGENT_DIR/scripts/setup-apps.sh" || warn "Nie udało się przygotować Dockera dla aplikacji"

# Węzły KVM: budowa szablonów Windows (grupa kvm dla konta agenta, QEMU).
bash "$AGENT_DIR/scripts/setup-builder.sh" || warn "Nie udało się przygotować budowy szablonów"

# Węzły kontenerów: osobny zakres UID/GID dla każdego kontenera.
bash "$AGENT_DIR/scripts/harden-incus.sh" || warn "Nie udało się ustawić przydziału UID/GID dla Incusa"

# Ochrona hosta przed ucieczkami z maszyn: bez zagnieżdżonej wirtualizacji,
# aktualne jądro i automatyczne poprawki bezpieczeństwa.
bash "$AGENT_DIR/scripts/harden-host.sh" || warn "Nie udało się utwardzić hosta"

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
