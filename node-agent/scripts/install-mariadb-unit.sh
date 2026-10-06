#!/usr/bin/env bash
#
# Jednostki systemd instalacji MariaDB zlecanej z panelu. Agent zostawia
# /var/lib/virthub/mariadb/request; jednostka .path uruchamia jako root
# scripts/setup-mariadb.sh (kopia w /usr/local/lib/virthub — agent nie może
# jej podmienić, bo /usr jest dla niego tylko do odczytu).
#
set -Eeuo pipefail

AGENT_DIR="/opt/virthub-agent"
AGENT_USER="virthub"
STATE_DIR="/var/lib/virthub/mariadb"

command -v systemctl >/dev/null 2>&1 || exit 0
install -d -m 0755 /usr/local/lib/virthub
install -m 0755 "$AGENT_DIR/scripts/setup-mariadb.sh" /usr/local/lib/virthub/setup-mariadb.sh
if id "$AGENT_USER" >/dev/null 2>&1; then
    install -d -m 0750 -o "$AGENT_USER" "$STATE_DIR"
else
    install -d -m 0750 "$STATE_DIR"
fi

cat > /etc/systemd/system/virthub-mariadb.path <<UNITEOF
[Unit]
Description=VirtHub — zlecenie instalacji MariaDB z panelu

[Path]
PathExists=$STATE_DIR/request
Unit=virthub-mariadb.service

[Install]
WantedBy=multi-user.target
UNITEOF

cat > /etc/systemd/system/virthub-mariadb.service <<UNITEOF
[Unit]
Description=VirtHub — instalacja serwera baz MariaDB dla aplikacji
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
TimeoutStartSec=1200
Environment=HOME=/root
# Zlecenie przenosimy przed startem — inaczej .path odpalałby usługę w pętli.
ExecStartPre=/bin/mv -f $STATE_DIR/request $STATE_DIR/request.run
ExecStart=/bin/bash /usr/local/lib/virthub/setup-mariadb.sh
UNITEOF

systemctl daemon-reload
systemctl reset-failed virthub-mariadb.path virthub-mariadb.service >/dev/null 2>&1 || true
systemctl enable virthub-mariadb.path >/dev/null 2>&1
systemctl restart virthub-mariadb.path
