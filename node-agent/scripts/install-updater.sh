#!/usr/bin/env bash
#
# Jednostki systemd zdalnej aktualizacji węzła (zlecanej z panelu).
#
# Agent zostawia plik /var/lib/virthub/update/request; jednostka .path to
# widzi i uruchamia jako root usługę, która wykonuje update-node.sh. Skrypt
# jest kopiowany do /run przed startem — aktualizacja podmienia go w trakcie
# działania, a bash czyta wykonywany plik kawałkami.
#
set -Eeuo pipefail

AGENT_DIR="/opt/virthub-agent"
AGENT_USER="virthub"
STATE_DIR="/var/lib/virthub/update"

install -d -m 0755 /usr/local/lib/virthub
install -m 0755 "$AGENT_DIR/scripts/update-node.sh" /usr/local/lib/virthub/update-node.sh

# Agent zapisuje tu zlecenie, root — stan i log aktualizacji.
install -d -m 0755 -o "$AGENT_USER" "$STATE_DIR"

# Źródło kodu ustala właściciel węzła, nie panel.
if [ ! -f /etc/virthub-agent/update.env ]; then
    cat > /etc/virthub-agent/update.env <<'ENVEOF'
# Skąd węzeł pobiera aktualizacje agenta (repozytorium GitHub i gałąź).
VH_UPDATE_REPO=TeYroXOfficial/virthub
VH_UPDATE_BRANCH=main
ENVEOF
    chmod 644 /etc/virthub-agent/update.env
fi

cat > /etc/systemd/system/virthub-agent-update.path <<UNITEOF
[Unit]
Description=VirtHub — zlecenie aktualizacji węzła z panelu

[Path]
PathExists=$STATE_DIR/request
Unit=virthub-agent-update.service

[Install]
WantedBy=multi-user.target
UNITEOF

cat > /etc/systemd/system/virthub-agent-update.service <<'UNITEOF'
[Unit]
Description=VirtHub — aktualizacja agenta węzła
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
TimeoutStartSec=1800
# Zlecenie zdejmujemy, zanim cokolwiek się uruchomi: gdyby skrypt nie wystartował,
# .path odpalałby usługę w pętli, aż systemd wyłączyłby ją na dobre.
ExecStartPre=/bin/rm -f /var/lib/virthub/update/request
# /run jest montowany z noexec — skrypt czyta bash, nie uruchamiamy pliku wprost.
ExecStart=/bin/bash -c 'install -m 0700 /usr/local/lib/virthub/update-node.sh /run/virthub-update-node.sh && exec /bin/bash /run/virthub-update-node.sh --unattended'
UNITEOF

systemctl daemon-reload
# Wcześniejsze wersje jednostki mogły wpaść w „start-limit-hit" — bez
# zresetowania .path zostaje wyłączona i zlecenia wiszą w kolejce.
systemctl reset-failed virthub-agent-update.path virthub-agent-update.service >/dev/null 2>&1 || true
systemctl enable virthub-agent-update.path >/dev/null 2>&1
systemctl restart virthub-agent-update.path
