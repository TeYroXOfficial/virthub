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
# Usługa systemd nie ustawia HOME — composer w instalatorze bez niego nie ruszy.
export HOME="${HOME:-/root}"
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

WORK="$(mktemp -d /tmp/virthub-update-panel.XXXXXX)"
INSTALLER="$WORK/install-panel.sh"
trap 'rm -rf "$WORK"' EXIT

ARGS=()
RELEASE_JSON="$STATE_DIR/release.json"
TARGET=""

# --- wydanie z portalu (panel z licencją) -------------------------------------
#
# Panel podaje wersję, adres, SHA-256 i podpis. Podpis sprawdzamy kluczem z
# /etc/virthub/panel.conf (zapisanym przy instalacji, root) — panel nie ma
# wpływu ani na klucz, ani na to, co zostanie uruchomione bez poprawnego podpisu.
if [ -f "$RELEASE_JSON" ]; then
    write_status running "Pobieram wydanie z portalu"
    read -r R_VERSION R_URL R_SHA R_SIG < <(php -r '
        $d = json_decode((string) file_get_contents($argv[1]), true) ?: [];
        echo ($d["version"] ?? ""), " ", ($d["url"] ?? ""), " ", ($d["sha256"] ?? ""), " ", ($d["signature"] ?? ""), "\n";' "$RELEASE_JSON")
    rm -f "$RELEASE_JSON"
    SEMVER='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'
    [[ "$R_VERSION" =~ $SEMVER ]] && [[ "$R_SHA" =~ ^[0-9a-f]{64}$ ]] && [[ "$R_URL" =~ ^https?:// ]] \
        || { write_status failed "Nieprawidłowy opis wydania od panelu."; exit 1; }
    [ -n "${PORTAL_PUBLIC_KEY:-}" ] || { write_status failed "Brak klucza portalu (PORTAL_PUBLIC_KEY w $CONF) — uruchom instalator z --portal-key."; exit 1; }

    # Bez cofania wersji: semver docelowej musi być wyższy od zainstalowanej.
    CURRENT="$(tr -d '[:space:]' < "$ROOT_DIR/VERSION" 2>/dev/null || true)"
    if [ -n "$CURRENT" ] && ! php -r '
        $p = fn ($v) => preg_match("/^(\\d+)\\.(\\d+)\\.(\\d+)(?:-([0-9A-Za-z.-]+))?/", $v, $m) ? $m : null;
        [$a, $b] = [$p($argv[1]), $p($argv[2])];
        if (! $a || ! $b) exit(0);
        for ($i = 1; $i <= 3; $i++) { if ((int) $a[$i] !== (int) $b[$i]) exit((int) $a[$i] > (int) $b[$i] ? 0 : 1); }
        $x = $a[4] ?? ""; $y = $b[4] ?? "";
        exit($x === $y ? 1 : ($x === "" ? 0 : ($y === "" ? 1 : (version_compare($x, $y) > 0 ? 0 : 1))));
    ' "$R_VERSION" "$CURRENT"; then
        write_status failed "Wydanie $R_VERSION nie jest nowsze od zainstalowanego $CURRENT."
        exit 1
    fi

    curl -fsSL --max-time 900 "$R_URL" -o "$WORK/release.tar.gz"
    [ "$(sha256sum "$WORK/release.tar.gz" | cut -d' ' -f1)" = "$R_SHA" ] \
        || { write_status failed "Suma SHA-256 paczki $R_VERSION się nie zgadza."; exit 1; }
    { printf '\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00'; printf '%s' "$PORTAL_PUBLIC_KEY" | base64 -d; } > "$WORK/key.der"
    printf '%s' "$R_SIG" | base64 -d > "$WORK/sig.bin"
    printf 'virthub-release\n%s\n%s' "$R_VERSION" "$R_SHA" > "$WORK/msg.bin"
    openssl pkeyutl -verify -pubin -keyform DER -inkey "$WORK/key.der" -rawin -in "$WORK/msg.bin" -sigfile "$WORK/sig.bin" >/dev/null 2>&1 \
        || { write_status failed "Podpis Ed25519 wydania $R_VERSION jest nieprawidłowy — przerwano."; exit 1; }
    echo "✓ Wydanie $R_VERSION: podpis Ed25519 i SHA-256 poprawne"

    mkdir -p "$WORK/src"
    tar -xzf "$WORK/release.tar.gz" -C "$WORK/src" --strip-components=1
    [ -f "$WORK/src/control-plane/artisan" ] && [ -f "$WORK/src/infra/install-panel.sh" ] \
        || { write_status failed "Paczka $R_VERSION nie zawiera panelu."; exit 1; }
    cp "$WORK/src/infra/install-panel.sh" "$INSTALLER"
    ARGS+=(--source "$WORK/src")
    TARGET="$R_VERSION"
else
write_status running "Pobieram najnowszy instalator"

# Najnowszy instalator z tej samej gałęzi — nowa wersja panelu może wymagać
# kroków, których stary instalator nie zna. Kopia w /tmp, bo instalator
# podmienia pliki repozytorium, z którego mógłby być czytany.
if [ -d "$ROOT_DIR/.git" ]; then
    git -C "$ROOT_DIR" fetch --quiet origin
    git -C "$ROOT_DIR" show origin/HEAD:infra/install-panel.sh > "$INSTALLER"
else
    curl -fsSL "https://raw.githubusercontent.com/${VH_UPDATE_REPO:-TeYroXOfficial/virthub}/${VH_UPDATE_BRANCH:-main}/infra/install-panel.sh" -o "$INSTALLER"
fi

if [ -n "${REPO_URL:-}" ]; then ARGS+=(--repo "$REPO_URL"); fi
fi

[ -n "${DOMAIN:-}" ] && ARGS+=(--domain "$DOMAIN")
[ -n "${ADMIN_EMAIL:-}" ] && ARGS+=(--email "$ADMIN_EMAIL")
[ -n "${BRAND:-}" ] && ARGS+=(--brand "$BRAND")
[ "${NO_TLS:-0}" = "1" ] && ARGS+=(--no-tls)

write_status running "Instaluję nową wersję"
bash "$INSTALLER" "${ARGS[@]}" </dev/null

if [ -n "$TARGET" ]; then
    write_status done "Zaktualizowano do $TARGET"
else
    VERSION="$(git -C "$ROOT_DIR" rev-parse --short HEAD 2>/dev/null || echo '?')"
    write_status done "Zaktualizowano do $VERSION"
fi
