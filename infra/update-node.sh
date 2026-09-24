#!/usr/bin/env bash
#
# Ręczna aktualizacja zarejestrowanego węzła VirtHub (KVM albo LXC):
#
#   curl -sSL https://raw.githubusercontent.com/TeYroXOfficial/virthub/main/infra/update-node.sh | sudo bash
#
# Pobiera i uruchamia node-agent/scripts/update-node.sh z tej samej gałęzi.
# Pierwsze uruchomienie włącza też aktualizacje zlecane z panelu
# (Administracja → Aktualizacje). Inne repozytorium lub gałąź:
# VH_UPDATE_REPO=owner/repo VH_UPDATE_BRANCH=gałąź w /etc/virthub-agent/update.env.
#
set -euo pipefail

REPO="${VH_UPDATE_REPO:-TeYroXOfficial/virthub}"
BRANCH="${VH_UPDATE_BRANCH:-main}"

if [ -f /etc/virthub-agent/update.env ]; then
    # shellcheck disable=SC1091
    . /etc/virthub-agent/update.env
    REPO="${VH_UPDATE_REPO:-$REPO}"
    BRANCH="${VH_UPDATE_BRANCH:-$BRANCH}"
fi

SCRIPT="$(mktemp /tmp/virthub-update-node.XXXXXX)"
trap 'rm -f "$SCRIPT"' EXIT
curl -fsSL "https://raw.githubusercontent.com/$REPO/$BRANCH/node-agent/scripts/update-node.sh" -o "$SCRIPT"
VH_UPDATE_REPO="$REPO" VH_UPDATE_BRANCH="$BRANCH" bash "$SCRIPT" "$@"
