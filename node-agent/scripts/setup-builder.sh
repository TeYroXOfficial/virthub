#!/usr/bin/env bash
#
# Węzły KVM: budowa szablonów Windows (Packer uruchamia QEMU jako konto agenta).
# Konto agenta dostaje grupę kvm (/dev/kvm), a węzeł narzędzia QEMU. Na węźle
# kontenerów nic nie robi.
#
set -euo pipefail

AGENT_USER="virthub"
command -v virsh >/dev/null 2>&1 || exit 0
id "$AGENT_USER" >/dev/null 2>&1 || exit 0

if getent group kvm >/dev/null 2>&1 && ! id -nG "$AGENT_USER" | tr ' ' '\n' | grep -qx kvm; then
    usermod -aG kvm "$AGENT_USER"
    echo "  ✓ Konto $AGENT_USER w grupie kvm (budowa szablonów)"
fi

if ! command -v qemu-system-x86_64 >/dev/null 2>&1 || ! command -v qemu-img >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y -qq qemu-system-x86 qemu-utils >/dev/null 2>&1 \
        && echo "  ✓ Zainstalowano QEMU do budowy szablonów" \
        || echo "  ! Nie udało się zainstalować qemu-system-x86 — budowa szablonów nie zadziała"
fi

install -d -o "$AGENT_USER" -m 0750 /var/lib/virthub/packer
