#!/usr/bin/env bash
#
# Aktualizacja panelu VirtHub z zapisanymi parametrami instalacji.
#
# Uruchamiana przez virthub-panel-update.service (jako root) po zleceniu
# z panelu (Administracja → Aktualizacje) albo ręcznie:
#   sudo bash /opt/virthub/infra/update-panel.sh
#
# Parametry (domena, e-mail, repozytorium) zapisuje instalator w
# /etc/virthub/panel.conf — panel nie ma wpływu na to, skąd pobierany jest kod.
#
set -Eeuo pipefail

UNATTENDED=0
[ "${1:-}" = "--unattended" ] && UNATTENDED=1

CONF="/etc/virthub/panel.conf"
STATE_DIR="/var/lib/virthub-panel"
ROOT_DIR="/opt/virthub"

[ "$(id -u)" -eq 0 ] || { echo "Uruchom jako root." >&2; exit 1; }
[ -f "$CONF" ] || { echo "Brak $CONF — uruchom raz instalator panelu ręcznie." >&2; exit 1; }

# shellcheck disable=SC1090
. "$CONF"

mkdir -p "$STATE_DIR"
# Zlecenie zdejmujemy od razu — jednostka .path uruchomiłaby nas ponownie.
rm -f "$STATE_DIR/request"
[ "$UNATTENDED" -eq 1 ] && exec >"$STATE_DIR/last.log" 2>&1

STARTED="$(date +%s)"
write_status() {
    local state="$1" message="${2:-}" finished=""
    case "$state" in done|failed) finished=",\"finished_at\":$(date +%s)" ;; esac
    message="${message//\\/\\\\}"; message="${message//\"/\\\"}"
    printf '{"state":"%s","started_at":%s%s,"message":"%s"}\n' "$state" "$STARTED" "$finished" "$message" \
        > "$STATE_DIR/status.json.tmp"
    mv "$STATE_DIR/status.json.tmp" "$STATE_DIR/status.json"
    chmod 644 "$STATE_DIR/status.json"
    chown www-data:www-data "$STATE_DIR/status.json" 2>/dev/null || true
}
trap 'rc=$?; write_status failed "Aktualizacja przerwana w linii $LINENO (kod $rc). Szczegóły w logu."; exit $rc' ERR

write_status running "Pobieram najnowszy instalator"

# Najnowszy instalator z tej samej gałęzi — nowa wersja panelu może wymagać
# kroków, których stary instalator nie zna. Kopia w /tmp, bo instalator
# podmienia pliki repozytorium, z którego mógłby być czytany.
INSTALLER="$(mktemp /tmp/virthub-install-panel.XXXXXX)"
trap 'rm -f "$INSTALLER"' EXIT
if [ -d "$ROOT_DIR/.git" ]; then
    git -C "$ROOT_DIR" fetch --quiet origin
    git -C "$ROOT_DIR" show origin/HEAD:infra/install-panel.sh > "$INSTALLER"
else
    curl -fsSL "https://raw.githubusercontent.com/${VH_UPDATE_REPO:-TeYroXOfficial/virthub}/${VH_UPDATE_BRANCH:-main}/infra/install-panel.sh" -o "$INSTALLER"
fi

ARGS=()
[ -n "${DOMAIN:-}" ] && ARGS+=(--domain "$DOMAIN")
[ -n "${ADMIN_EMAIL:-}" ] && ARGS+=(--email "$ADMIN_EMAIL")
[ -n "${REPO_URL:-}" ] && ARGS+=(--repo "$REPO_URL")
[ -n "${BRAND:-}" ] && ARGS+=(--brand "$BRAND")
[ "${NO_TLS:-0}" = "1" ] && ARGS+=(--no-tls)

write_status running "Instaluję nową wersję"
bash "$INSTALLER" "${ARGS[@]}" </dev/null

VERSION="$(git -C "$ROOT_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')"
write_status done "Zaktualizowano do $VERSION"
