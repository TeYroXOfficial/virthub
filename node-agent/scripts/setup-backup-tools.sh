#!/usr/bin/env bash
#
# Narzędzia celów kopii: rclone (Google Drive, B2, OneDrive… w Administracja →
# Kopie zapasowe). proxmox-backup-client wymaga repozytorium Proxmox — skrypt
# instaluje go tylko wtedy, gdy repozytorium jest już skonfigurowane.
#
set -euo pipefail

command -v apt-get >/dev/null 2>&1 || exit 0
export DEBIAN_FRONTEND=noninteractive

if ! command -v rclone >/dev/null 2>&1; then
    apt-get install -y -qq rclone >/dev/null 2>&1 \
        && echo "  ✓ Zainstalowano rclone (cele kopii w chmurach)" \
        || echo "  ! Nie udało się zainstalować rclone — cele Rclone nie zadziałają na tym węźle"
fi

if ! command -v proxmox-backup-client >/dev/null 2>&1 && apt-cache policy proxmox-backup-client 2>/dev/null | grep -q Candidate: ; then
    apt-get install -y -qq proxmox-backup-client >/dev/null 2>&1 \
        && echo "  ✓ Zainstalowano proxmox-backup-client" || true
fi
