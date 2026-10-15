#!/usr/bin/env bash
#
# Węzły KVM: firmware UEFI (OVMF) i emulator TPM 2.0 (swtpm) — potrzebne dla
# maszyn z rozruchem UEFI i z vTPM (Windows 11 / Server 2025). Na węźle
# kontenerów nic nie robi.
#
set -euo pipefail

command -v virsh >/dev/null 2>&1 || exit 0
command -v apt-get >/dev/null 2>&1 || exit 0

missing=()
[ -e /usr/share/OVMF/OVMF_CODE_4M.fd ] || [ -e /usr/share/OVMF/OVMF_CODE.fd ] || missing+=(ovmf)
command -v swtpm >/dev/null 2>&1 || missing+=(swtpm swtpm-tools)

if [ "${#missing[@]}" -gt 0 ]; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y -qq "${missing[@]}" >/dev/null 2>&1 \
        && echo "  ✓ Zainstalowano ${missing[*]} (UEFI / vTPM)" \
        || echo "  ! Nie udało się zainstalować ${missing[*]} — maszyny z UEFI albo vTPM nie wystartują"
fi
