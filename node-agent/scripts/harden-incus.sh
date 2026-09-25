#!/usr/bin/env bash
#
# Przydział podrzędnych UID/GID roota dla Incusa: dość, żeby każdy kontener
# klienta dostał osobny zakres (security.idmap.isolated). Bez tego wszystkie
# kontenery dzielą jeden zakres, a root kontenera A — gdyby wydostał się z
# kontenera — miałby te same identyfikatory co pliki kontenera B.
#
# Idempotentne. Istniejący początek zakresu zostaje (działające kontenery
# mają mapowanie od niego), zwiększamy tylko rozmiar.
set -euo pipefail

command -v incus >/dev/null 2>&1 || exit 0

SIZE=1000000000
changed=0
for f in /etc/subuid /etc/subgid; do
    touch "$f"
    if awk -F: -v size="$SIZE" '$1 == "root" && $3 >= size { ok = 1 } END { exit !ok }' "$f"; then
        continue
    fi
    if grep -q '^root:' "$f"; then
        sed -i -E "s/^root:([0-9]+):[0-9]+$/root:\1:${SIZE}/" "$f"
    else
        echo "root:1000000:${SIZE}" >> "$f"
    fi
    changed=1
done

if [ "$changed" -eq 1 ]; then
    # Restart demona Incusa nie zatrzymuje działających kontenerów.
    systemctl restart incus
    echo "  ✓ Incus: osobne zakresy UID/GID dla kontenerów włączone"
fi
