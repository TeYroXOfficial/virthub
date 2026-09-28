#!/usr/bin/env bash
#
# Wypisuje ścieżkę Pythona 3.10+ dla agenta (na stdout; komunikaty na stderr).
#
# Systemowy python3, gdy jest wystarczająco nowy. Na starszych systemach
# (Debian 11, Ubuntu 20.04) — samodzielny CPython z python-build-standalone,
# instalowany przez uv do /opt/virthub-python. Nic w systemie się nie zmienia:
# systemowy python3 zostaje, jaki był.
#
set -euo pipefail

if python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 10) else 1)' 2>/dev/null; then
    command -v python3
    exit 0
fi

PY_DIR="/opt/virthub-python"
PY_VERSION="${VH_PYTHON_VERSION:-3.11}"
UV_VERSION="${VH_UV_VERSION:-0.8.17}"

export UV_PYTHON_INSTALL_DIR="$PY_DIR/pythons"
# Prawdziwa ścieżka, nie dowiązanie: Python z python-build-standalone szuka
# bibliotek standardowych względem własnego pliku.
if [ -x "$PY_DIR/bin/uv" ] && FOUND="$("$PY_DIR/bin/uv" python find --managed-python "$PY_VERSION" 2>/dev/null)"; then
    readlink -f "$FOUND"
    exit 0
fi

case "$(uname -m)" in
    x86_64|amd64) ARCH="x86_64" ;;
    aarch64|arm64) ARCH="aarch64" ;;
    *) echo "Nieobsługiwana architektura: $(uname -m)" >&2; exit 1 ;;
esac

echo "  ! Systemowy Python jest starszy niż 3.10 — instaluję osobny Python ${PY_VERSION} dla agenta w ${PY_DIR}" >&2
install -d -m 0755 "$PY_DIR/bin" "$PY_DIR/pythons"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

NAME="uv-${ARCH}-unknown-linux-gnu"
BASE="https://github.com/astral-sh/uv/releases/download/${UV_VERSION}"
curl -fsSL "$BASE/$NAME.tar.gz" -o "$TMP/uv.tar.gz"
curl -fsSL "$BASE/$NAME.tar.gz.sha256" -o "$TMP/uv.sha256"
EXPECTED="$(cut -d' ' -f1 "$TMP/uv.sha256")"
ACTUAL="$(sha256sum "$TMP/uv.tar.gz" | cut -d' ' -f1)"
[ -n "$EXPECTED" ] && [ "$EXPECTED" = "$ACTUAL" ] || { echo "Suma kontrolna uv się nie zgadza." >&2; exit 1; }
tar -xzf "$TMP/uv.tar.gz" -C "$TMP"
install -m 0755 "$TMP/$NAME/uv" "$PY_DIR/bin/uv"

"$PY_DIR/bin/uv" python install --quiet "$PY_VERSION" >&2
PYTHON="$("$PY_DIR/bin/uv" python find --managed-python "$PY_VERSION")"
chmod -R a+rX "$PY_DIR"
readlink -f "$PYTHON"
