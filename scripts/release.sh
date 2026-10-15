#!/usr/bin/env bash
#
# Paczka wydania VirtHub: sprawdza wersję (semver z pliku VERSION, zgodna z
# tagiem), wycina notatki z CHANGELOG.md i buduje archiwum z gita.
#
#   scripts/release.sh            # wersja z VERSION
#   scripts/release.sh v1.4.2     # wersja z tagu — musi zgadzać się z VERSION
#
# Wynik w dist/: virthub-<wersja>.tar.gz i notes-<wersja>.md. Publikację do
# portalu robi workflow .github/workflows/release.yml.
#
set -euo pipefail
cd "$(dirname "$0")/.."

SEMVER='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-((0|[1-9][0-9]*|[0-9]*[a-zA-Z-][0-9a-zA-Z-]*)(\.(0|[1-9][0-9]*|[0-9]*[a-zA-Z-][0-9a-zA-Z-]*))*))?(\+[0-9a-zA-Z-]+(\.[0-9a-zA-Z-]+)*)?$'

VERSION="$(tr -d '[:space:]' < VERSION)"
[[ "$VERSION" =~ $SEMVER ]] || { echo "VERSION ($VERSION) nie jest poprawnym semver." >&2; exit 1; }
if [ -n "${1:-}" ]; then
    TAG="${1#v}"
    [ "$TAG" = "$VERSION" ] || { echo "Tag v$TAG nie zgadza się z plikiem VERSION ($VERSION)." >&2; exit 1; }
fi

mkdir -p dist
NOTES="dist/notes-$VERSION.md"
# Sekcja „## [X.Y.Z]” do następnego nagłówka drugiego poziomu.
awk -v v="$VERSION" '
    /^## \[/ { if (on) exit; if (index($0, "## [" v "]") == 1) { on = 1; next } }
    on { print }
' CHANGELOG.md | sed '/./,$!d' > "$NOTES"
[ -s "$NOTES" ] || { echo "CHANGELOG.md nie ma sekcji ## [$VERSION]." >&2; exit 1; }

PACKAGE="dist/virthub-$VERSION.tar.gz"
git archive --format=tar --prefix="virthub-$VERSION/" HEAD | gzip -n -9 > "$PACKAGE"
echo "$PACKAGE ($(du -h "$PACKAGE" | cut -f1)), sha256 $(sha256sum "$PACKAGE" | cut -d' ' -f1)"
