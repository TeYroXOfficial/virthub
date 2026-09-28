#!/usr/bin/env bash
#
# Docker dla aplikacji (serwery gier, boty) — odpowiednik instalacji Wings.
#
# Instaluje Dockera tylko na życzenie (VH_APPS=1). Na węźle, który już go ma,
# pilnuje tylko, żeby agent był w grupie docker i miał katalog aplikacji —
# dlatego aktualizacja węzła może go wywoływać zawsze.
#
set -Eeuo pipefail

AGENT_USER="${AGENT_USER:-virthub}"
APPS_DIR="${VH_APPS_DIR:-/var/lib/virthub/apps}"

ok()   { printf '  ✓ %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*"; }

if ! command -v docker >/dev/null 2>&1; then
    [ "${VH_APPS:-0}" = "1" ] || exit 0

    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    if ! apt-get install -y -qq docker.io >/dev/null; then
        warn "Nie udało się zainstalować Dockera (pakiet docker.io)."
        exit 1
    fi
    ok "Docker zainstalowany"
fi

# Rotacja logów kontenerów (konsola aplikacji to ich logi) i live-restore —
# restart demona Dockera przy aktualizacji nie zatrzymuje serwerów gier.
# Istniejącej konfiguracji administratora nie ruszamy.
if [ ! -f /etc/docker/daemon.json ]; then
    install -d -m 0755 /etc/docker
    cat > /etc/docker/daemon.json <<'EOF'
{
    "log-driver": "json-file",
    "log-opts": { "max-size": "5m", "max-file": "2" },
    "live-restore": true
}
EOF
fi

systemctl enable --now docker >/dev/null 2>&1 || warn "Nie udało się uruchomić usługi docker."

if id "$AGENT_USER" >/dev/null 2>&1; then
    # Grupa docker daje dostęp do gniazda Dockera — agent zarządza kontenerami
    # aplikacji tak jak Wings (tylko przez API, bez powłoki).
    usermod -aG docker "$AGENT_USER"
    install -d -o "$AGENT_USER" -g "$(id -gn "$AGENT_USER")" -m 0750 "$APPS_DIR"
fi

ok "Aplikacje: Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo '?') gotowy"
