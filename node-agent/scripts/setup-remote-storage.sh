#!/usr/bin/env bash
#
# Klienci zdalnych magazynów (Infrastruktura → Magazyny danych): NFS, SMB/CIFS,
# CephFS, iSCSI i NVMe/TCP. StorPool i Lightbits mają własnych klientów
# (storpool, lbcli) instalowanych według dokumentacji producenta.
#
set -euo pipefail

command -v apt-get >/dev/null 2>&1 || exit 0
export DEBIAN_FRONTEND=noninteractive

want=()
command -v mount.nfs >/dev/null 2>&1 || want+=(nfs-common)
command -v mount.cifs >/dev/null 2>&1 || want+=(cifs-utils)
command -v mount.ceph >/dev/null 2>&1 || want+=(ceph-common)
command -v iscsiadm >/dev/null 2>&1 || want+=(open-iscsi)
command -v nvme >/dev/null 2>&1 || want+=(nvme-cli)

if [ ${#want[@]} -gt 0 ]; then
    apt-get install -y -qq "${want[@]}" >/dev/null 2>&1 \
        && echo "  ✓ Zainstalowano klientów magazynów: ${want[*]}" \
        || echo "  ! Nie udało się zainstalować: ${want[*]} — zdalne magazyny tego typu nie zadziałają na tym węźle"
fi
# NVMe/TCP (Lightbits) wymaga modułu jądra po każdym starcie.
if [ -d /etc/modules-load.d ] && ! grep -qs nvme_tcp /etc/modules-load.d/virthub-storage.conf; then
    echo nvme_tcp > /etc/modules-load.d/virthub-storage.conf
    modprobe nvme_tcp 2>/dev/null || true
fi
