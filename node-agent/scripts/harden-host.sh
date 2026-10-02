#!/usr/bin/env bash
#
# Utwardzenie hosta KVM przed ucieczkami z maszyn klientów do hosta
# (np. Januscape CVE-2026-53359, Zapscape CVE-2026-64561 — błędy w shadow MMU
# KVM na x86, osiągalne głównie przez zagnieżdżoną wirtualizację).
#
# 1. Wyłącza zagnieżdżoną wirtualizację (kvm_intel/kvm_amd nested=0) i pilnuje,
#    żeby nikt nie wyłączył EPT/NPT (wtedy KVM używa shadow MMU dla każdej maszyny).
# 2. Doinstalowuje aktualizacje jądra i włącza automatyczne poprawki
#    bezpieczeństwa (bez automatycznego restartu — o nim decyduje administrator).
#
# Idempotentne, wołane przez instalator i każdą aktualizację węzła.
# VH_ALLOW_NESTED=1 w /etc/virthub-agent/agent.env zostawia zagnieżdżanie włączone
# (świadoma decyzja administratora), VH_KERNEL_UPDATES=0 pomija aktualizację jądra.
set -uo pipefail

ok()   { printf '  ✓ %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*"; }

ENV_FILE=/etc/virthub-agent/agent.env
ALLOW_NESTED="${VH_ALLOW_NESTED:-}"
KERNEL_UPDATES="${VH_KERNEL_UPDATES:-}"
if [ -r "$ENV_FILE" ]; then
    [ -z "$ALLOW_NESTED" ] && ALLOW_NESTED="$(sed -n 's/^VH_ALLOW_NESTED=//p' "$ENV_FILE" | tail -n1)"
    [ -z "$KERNEL_UPDATES" ] && KERNEL_UPDATES="$(sed -n 's/^VH_KERNEL_UPDATES=//p' "$ENV_FILE" | tail -n1)"
fi
ALLOW_NESTED="${ALLOW_NESTED:-0}"
KERNEL_UPDATES="${KERNEL_UPDATES:-1}"
CONF=/etc/modprobe.d/virthub-kvm.conf

# --- zagnieżdżona wirtualizacja i shadow paging -------------------------------

running_vms() {
    command -v virsh >/dev/null 2>&1 || { echo 0; return; }
    virsh -c qemu:///system list --name 2>/dev/null | grep -c . || true
}

# Inne pliki modprobe.d mogły włączyć nested albo wyłączyć EPT/NPT — wyłączamy
# takie linie (kopia .virthub-bak), żeby nasz plik był jedynym źródłem prawdy.
neutralise() {
    local pattern="$1" f
    for f in /etc/modprobe.d/*.conf; do
        [ -f "$f" ] && [ "$f" != "$CONF" ] || continue
        if grep -Eq "^[[:space:]]*options[[:space:]]+kvm(_intel|_amd)?[[:space:]].*${pattern}" "$f"; then
            sed -i.virthub-bak -E "/^[[:space:]]*options[[:space:]]+kvm(_intel|_amd)?[[:space:]].*${pattern}/ s/^/# virthub: /" "$f"
            warn "Wyłączono „${pattern}” w $f (kopia: $f.virthub-bak)"
        fi
    done
}

neutralise '(ept|npt)=(0|N|n)'
if [ "$ALLOW_NESTED" = "1" ]; then
    rm -f "$CONF"
    warn "Zagnieżdżona wirtualizacja zostaje WŁĄCZONA (VH_ALLOW_NESTED=1) — host jest bardziej narażony na ucieczki z maszyn."
else
    neutralise 'nested=(1|Y|y)'
    mkdir -p /etc/modprobe.d
    cat > "$CONF" <<'EOF'
# VirtHub: zagnieżdżona wirtualizacja wyłączona — główna droga ataku ucieczek
# z maszyn do hosta przez shadow MMU KVM (Januscape, Zapscape). Żeby świadomie
# ją włączyć, ustaw VH_ALLOW_NESTED=1 w /etc/virthub-agent/agent.env i zaktualizuj węzeł.
options kvm_intel nested=0
options kvm_amd nested=0
EOF
    for mod in kvm_intel kvm_amd; do
        param="/sys/module/$mod/parameters/nested"
        [ -r "$param" ] || continue
        case "$(cat "$param")" in
            1|Y|y)
                if [ "$(running_vms)" = "0" ] && modprobe -r "$mod" 2>/dev/null && modprobe "$mod"; then
                    ok "Zagnieżdżona wirtualizacja wyłączona ($mod przeładowany)"
                else
                    warn "Zagnieżdżona wirtualizacja wyłączy się po restarcie węzła (działają maszyny — modułu $mod nie da się teraz przeładować)."
                fi ;;
            *) ok "Zagnieżdżona wirtualizacja wyłączona" ;;
        esac
    done
fi

# --- aktualizacje jądra ---------------------------------------------------------

if [ "$KERNEL_UPDATES" != "0" ] && command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    # Metapakiety jądra, które faktycznie są zainstalowane (Debian, Ubuntu, chmury).
    metas="$(dpkg-query -W -f='${db:Status-Abbrev} ${Package}\n' 'linux-image-*' 'linux-generic*' 'linux-virtual*' 'linux-kvm*' 2>/dev/null \
        | awk '$1 ~ /^ii/ && $2 !~ /[0-9]/ {print $2}' | sort -u | tr '\n' ' ')"
    apt-get update -qq >/dev/null 2>&1 || true
    if [ -n "$metas" ]; then
        # shellcheck disable=SC2086
        if apt-get install -y -qq --only-upgrade $metas >/dev/null 2>&1; then
            ok "Jądro z najnowszymi poprawkami dostępnymi w repozytorium"
        else
            warn "Nie udało się zaktualizować jądra (apt-get install --only-upgrade $metas)."
        fi
    fi

    # Poprawki bezpieczeństwa (w tym jądra) instalują się same; restart węzła
    # zostaje decyzją administratora — panel pokazuje, że jest potrzebny.
    if ! dpkg-query -W -f='${db:Status-Abbrev}' unattended-upgrades 2>/dev/null | grep -q '^ii'; then
        apt-get install -y -qq unattended-upgrades >/dev/null 2>&1 || warn "Nie udało się zainstalować unattended-upgrades."
    fi
    if dpkg-query -W -f='${db:Status-Abbrev}' unattended-upgrades 2>/dev/null | grep -q '^ii'; then
        cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
        cat > /etc/apt/apt.conf.d/52virthub-unattended <<'EOF'
// VirtHub: poprawki bezpieczeństwa automatycznie, bez restartu węzła z maszynami klientów.
Unattended-Upgrade::Automatic-Reboot "false";
EOF
        ok "Automatyczne poprawki bezpieczeństwa włączone (bez automatycznego restartu)"
    fi
fi

running="$(uname -r)"
newest="$(ls -1 /boot/vmlinuz-* 2>/dev/null | sed 's|/boot/vmlinuz-||' | sort -V | tail -n1)"
if [ -e /run/reboot-required ] || { [ -n "$newest" ] && [ "$newest" != "$running" ]; }; then
    warn "Zainstalowane jest nowsze jądro (${newest:-?}) niż działające ($running) — poprawki zadziałają po restarcie węzła."
fi
exit 0
