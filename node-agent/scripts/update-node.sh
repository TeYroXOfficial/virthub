#!/usr/bin/env bash
#
# Aktualizacja węzła VirtHub — pobiera kod agenta i wdraża go (apply-update.sh).
#
# Uruchamiana:
#   * ręcznie:  curl -sSL …/infra/update-node.sh | sudo bash
#   * z panelu: usługa virthub-agent-update.service (jako root, --unattended),
#               wyzwalana plikiem-zleceniem zostawionym przez agenta.
#
# Źródło kodu wyłącznie z lokalnego /etc/virthub-agent/update.env — panel może
# aktualizację tylko wyzwolić, nie może wskazać, skąd pobrać kod.
#
set -Eeuo pipefail

UNATTENDED=0
[ "${1:-}" = "--unattended" ] && UNATTENDED=1

CONF="/etc/virthub-agent/update.env"
STATE_DIR="/var/lib/virthub/update"
# shellcheck disable=SC1090
[ -f "$CONF" ] && . "$CONF"
VH_UPDATE_REPO="${VH_UPDATE_REPO:-TeYroXOfficial/virthub}"
VH_UPDATE_BRANCH="${VH_UPDATE_BRANCH:-main}"
VH_TARBALL="${VH_TARBALL:-}"

[ "$(id -u)" -eq 0 ] || { echo "Uruchom jako root (sudo bash)." >&2; exit 1; }
[ -d /opt/virthub-agent ] || { echo "Brak /opt/virthub-agent — to nie jest zainstalowany węzeł VirtHub." >&2; exit 1; }

mkdir -p "$STATE_DIR"
# Zlecenie zdejmujemy od razu — jednostka .path uruchomiłaby nas ponownie.
rm -f "$STATE_DIR/request"

if [ "$UNATTENDED" -eq 1 ]; then
    exec >"$STATE_DIR/last.log" 2>&1
fi

STARTED="$(date +%s)"
TARGET=""

write_status() {
    local state="$1" message="${2:-}"
    python3 - "$STATE_DIR/status.json" "$state" "$STARTED" "$TARGET" "$message" <<'PYEOF'
import json, sys, time
path, state, started, target, message = sys.argv[1:6]
data = {"state": state, "started_at": int(started), "target": target or None, "message": message}
if state in ("done", "failed"):
    data["finished_at"] = int(time.time())
open(path + ".tmp", "w").write(json.dumps(data))
import os; os.replace(path + ".tmp", path); os.chmod(path, 0o644)
PYEOF
}

trap 'rc=$?; write_status failed "Aktualizacja przerwana w linii $LINENO (kod $rc): $BASH_COMMAND"; exit $rc' ERR

write_status running "Pobieram kod"
echo "==> Aktualizacja węzła z $VH_UPDATE_REPO ($VH_UPDATE_BRANCH)"

# Konkretny commit zamiast „najnowszego archiwum gałęzi": węzeł wie, którą
# wersję ma, a panel porównuje ją z najnowszą.
if [ -z "$VH_TARBALL" ]; then
    TARGET="$(curl -fsSL --max-time 20 -H 'Accept: application/vnd.github+json' \
        "https://api.github.com/repos/$VH_UPDATE_REPO/commits/$VH_UPDATE_BRANCH" 2>/dev/null \
        | python3 -c 'import json,sys; print(json.load(sys.stdin)["sha"])' 2>/dev/null)" || TARGET=""
    if [ -n "$TARGET" ]; then
        VH_TARBALL="https://github.com/$VH_UPDATE_REPO/archive/$TARGET.tar.gz"
    else
        VH_TARBALL="https://github.com/$VH_UPDATE_REPO/archive/refs/heads/$VH_UPDATE_BRANCH.tar.gz"
    fi
fi

WORK="$(mktemp -d /tmp/virthub-update.XXXXXX)"
trap 'rm -rf "$WORK"' EXIT

curl -fsSL --max-time 300 "$VH_TARBALL" | tar -xz -C "$WORK"
SRC="$(find "$WORK" -mindepth 2 -maxdepth 2 -type d -name node-agent | head -1)"
[ -n "$SRC" ] && [ -f "$SRC/scripts/apply-update.sh" ] || {
    write_status failed "Archiwum nie zawiera node-agent/scripts/apply-update.sh"
    exit 1
}

write_status running "Wdrażam ${TARGET:0:12}"
bash "$SRC/scripts/apply-update.sh" "$SRC" "$TARGET"

if [ -n "$TARGET" ]; then
    write_status done "Zaktualizowano do ${TARGET:0:12}"
else
    write_status done "Zaktualizowano"
fi
echo "==> Gotowe"
