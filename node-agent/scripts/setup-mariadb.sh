#!/usr/bin/env bash
#
# Instalacja serwera baz MariaDB dla aplikacji — zlecana z panelu
# (Administracja → Aplikacje → Bazy danych → „Zainstaluj MariaDB na węźle”).
# Uruchamia ją jako root jednostka virthub-mariadb.service.
#
# Zlecenie zawiera adres panelu i decyzję o zaporze. Skrypt:
#   * instaluje mariadb-server (jeśli go nie ma),
#   * nasłuchuje na wszystkich adresach (aplikacje łączą się z bazami),
#   * zakłada/odświeża konto `virthub_panel` dostępne TYLKO z adresu panelu,
#   * otwiera port w ufw wyłącznie na wyraźne życzenie administratora,
#   * zapisuje dane konta w credentials.json (czyta je tylko agent).
#
set -Eeuo pipefail

STATE_DIR="/var/lib/virthub/mariadb"
AGENT_USER="virthub"
ADMIN_USER="virthub_panel"
CONF="/etc/mysql/mariadb.conf.d/60-virthub.cnf"

mkdir -p "$STATE_DIR"
exec >>"$STATE_DIR/setup.log" 2>&1
echo "=== $(date -Is) ==="

status() { # stan komunikat [wersja]
    python3 - "$STATE_DIR/status.json" "$@" <<'PY'
import json, os, sys, time
path, state, message = sys.argv[1], sys.argv[2], sys.argv[3]
data = {"state": state, "message": message, "updated_at": int(time.time())}
if len(sys.argv) > 4:
    data["version"] = sys.argv[4]
with open(path + ".tmp", "w") as fh:
    json.dump(data, fh)
os.replace(path + ".tmp", path)
PY
    chown "$AGENT_USER" "$STATE_DIR/status.json" 2>/dev/null || true
}
fail() { trap - ERR; status failed "$1"; echo "BŁĄD: $1"; exit 1; }
trap 'fail "Instalacja przerwana (linia $LINENO) — szczegóły w $STATE_DIR/setup.log na węźle."' ERR

REQUEST="$(cat "$STATE_DIR/request.run" 2>/dev/null || echo '{}')"
rm -f "$STATE_DIR/request.run"
read -r PANEL_HOST OPEN_FIREWALL < <(python3 -c '
import ipaddress, json, sys
req = json.loads(sys.argv[1])
host = str(req.get("panel_host", ""))
try:
    host = str(ipaddress.ip_address(host))
except ValueError:
    host = "-"
print(host, "1" if req.get("open_firewall") is True else "0")
' "$REQUEST")
# Tylko konkretny adres IP — nigdy „%” dla konta z pełnymi uprawnieniami.
[ "$PANEL_HOST" != "-" ] || fail "Zlecenie nie zawiera poprawnego adresu panelu."

status running "Instalacja MariaDB…"
export DEBIAN_FRONTEND=noninteractive

if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
    command -v apt-get >/dev/null 2>&1 || fail "Automatyczna instalacja obsługuje tylko Debiana i Ubuntu."
    apt-get update -qq
    apt-get install -y -qq mariadb-server >/dev/null || fail "Nie udało się zainstalować pakietu mariadb-server."
    echo "mariadb-server zainstalowany"
elif ! command -v mariadb >/dev/null 2>&1; then
    fail "Na węźle działa inny serwer MySQL — dodaj go w panelu ręcznie."
fi

status running "Konfiguracja MariaDB…"
install -d -m 0755 /etc/mysql/mariadb.conf.d
cat > "$CONF" <<'CNF'
# VirtHub: bazy danych aplikacji. Plik zarządzany przez panel.
[mysqld]
bind-address = 0.0.0.0
max_connections = 500
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
# Konta są przypisane do adresów IP — bez odpytywania DNS przy logowaniu.
skip-name-resolve
CNF

systemctl enable mariadb >/dev/null 2>&1 || true
systemctl restart mariadb
for _ in $(seq 1 30); do
    mariadb -e 'SELECT 1' >/dev/null 2>&1 && break
    sleep 1
done
mariadb -e 'SELECT 1' >/dev/null 2>&1 || fail "MariaDB nie wystartowała po konfiguracji."

PASSWORD="$(python3 -c 'import secrets, string; a = string.ascii_letters + string.digits; print("".join(secrets.choice(a) for _ in range(40)))')"
# Poprzednie konta panelu (np. po zmianie adresu panelu) usuwamy — zostaje jedno, z aktualnego adresu.
for old in $(mariadb -N -e "SELECT Host FROM mysql.user WHERE User = '$ADMIN_USER'"); do
    mariadb -e "DROP USER '$ADMIN_USER'@'$old'"
done
mariadb <<SQL
CREATE USER '$ADMIN_USER'@'$PANEL_HOST' IDENTIFIED BY '$PASSWORD';
GRANT ALL PRIVILEGES ON *.* TO '$ADMIN_USER'@'$PANEL_HOST' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

PORT="$(mariadb -N -e 'SELECT @@port')"
VERSION="$(mariadb -N -e 'SELECT VERSION()')"

if [ "$OPEN_FIREWALL" = "1" ]; then
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
        ufw allow "$PORT/tcp" >/dev/null && echo "ufw: otwarto port $PORT/tcp (na życzenie administratora)"
    else
        echo "ufw nieaktywny — zapory nie zmieniano"
    fi
fi

umask 077
python3 - "$STATE_DIR/credentials.json" "$ADMIN_USER" "$PASSWORD" "$PORT" "$PANEL_HOST" <<'PY'
import json, sys
path, user, password, port, host = sys.argv[1:6]
with open(path, "w") as fh:
    json.dump({"username": user, "password": password, "port": int(port), "allowed_from": host}, fh)
PY
chown "$AGENT_USER" "$STATE_DIR/credentials.json"
chmod 600 "$STATE_DIR/credentials.json"

trap - ERR
status done "MariaDB gotowa." "$VERSION"
echo "Gotowe: $VERSION, port $PORT, konto $ADMIN_USER@$PANEL_HOST"
