#!/usr/bin/env bash
#
# Aktualizacja węzła VirtHub — pobiera kod agenta i wdraża go (apply-update.sh).
#
# Uruchamiana:
#   * ręcznie:  curl -sSL …/infra/update-node.sh | sudo bash
#   * z panelu: usługa virthub-agent-update.service (jako root, --unattended),
#               wyzwalana plikiem-zleceniem zostawionym przez agenta.
#
# Z portalem (licencja w panelu) panel podaje wydanie: wersję, adres, SHA-256
# i podpis — węzeł instaluje je tylko z poprawnym podpisem kluczem przypiętym
# w /etc/virthub-agent/update.env. Bez wydania od panelu (ręczne uruchomienie)
# źródłem jest repozytorium z update.env.
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
export HOME="${HOME:-/root}"
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

WORK="$(mktemp -d /tmp/virthub-update.XXXXXX)"
trap 'rm -rf "$WORK"' EXIT

SEMVER='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'
RELEASE_JSON="$STATE_DIR/release.json"

# Wydanie z portalu, zlecone przez panel: adres i podpis przychodzą z panelu,
# ale podpis sprawdzamy kluczem przypiętym lokalnie (VH_RELEASE_PUBLIC_KEY) —
# panel nie może podsunąć ani obcej paczki, ani obcego klucza.
verify_release() {  # plik wersja sha256 podpis
    local file="$1" version="$2" sha="$3" sig="$4"
    [ -n "${VH_RELEASE_PUBLIC_KEY:-}" ] || { echo "Węzeł nie ma przypiętego klucza portalu (VH_RELEASE_PUBLIC_KEY w $CONF)." >&2; return 1; }
    [ "$(sha256sum "$file" | cut -d' ' -f1)" = "$sha" ] || { echo "Suma SHA-256 paczki się nie zgadza." >&2; return 1; }
    { printf '\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00'; printf '%s' "$VH_RELEASE_PUBLIC_KEY" | base64 -d; } > "$WORK/key.der"
    printf '%s' "$sig" | base64 -d > "$WORK/sig.bin"
    printf 'virthub-release\n%s\n%s' "$version" "$sha" > "$WORK/msg.bin"
    openssl pkeyutl -verify -pubin -keyform DER -inkey "$WORK/key.der" -rawin -in "$WORK/msg.bin" -sigfile "$WORK/sig.bin" >/dev/null 2>&1 \
        || { echo "Podpis Ed25519 paczki jest nieprawidłowy — przerywam." >&2; return 1; }
}

if [ -f "$RELEASE_JSON" ]; then
    read -r R_VERSION R_URL R_SHA R_SIG < <(python3 -c '
import json, sys
d = json.load(open(sys.argv[1]))
print(d.get("version", ""), d.get("url", ""), d.get("sha256", ""), d.get("signature", ""))' "$RELEASE_JSON")
    rm -f "$RELEASE_JSON"
    [[ "$R_VERSION" =~ $SEMVER ]] && [[ "$R_SHA" =~ ^[0-9a-f]{64}$ ]] && [[ "$R_URL" =~ ^https?:// ]] \
        || { write_status failed "Nieprawidłowy opis wydania od panelu"; exit 1; }
    TARGET="$R_VERSION"
    echo "==> Aktualizacja węzła do VirtHub $TARGET (portal)"
    curl -fsSL --max-time 600 "$R_URL" -o "$WORK/release.tar.gz"
    verify_release "$WORK/release.tar.gz" "$R_VERSION" "$R_SHA" "$R_SIG" || { write_status failed "Paczka $TARGET nie przeszła weryfikacji podpisu"; exit 1; }
    echo "  ✓ Podpis Ed25519 i suma SHA-256 poprawne"
    tar -xzf "$WORK/release.tar.gz" -C "$WORK"
    VH_TARBALL="verified"
else
    echo "==> Aktualizacja węzła z $VH_UPDATE_REPO ($VH_UPDATE_BRANCH)"
fi

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

[ "$VH_TARBALL" = "verified" ] || curl -fsSL --max-time 300 "$VH_TARBALL" | tar -xz -C "$WORK"
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
