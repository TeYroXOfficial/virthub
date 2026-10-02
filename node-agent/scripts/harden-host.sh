#!/usr/bin/env bash
#
# Utwardzenie hosta KVM przed ucieczkami z maszyn klientów do hosta
# (np. Januscape CVE-2026-53359, Zapscape CVE-2026-64561 — błędy w shadow MMU
# KVM na x86, osiągalne głównie przez zagnieżdżoną wirtualizację).
#
# 1. Zagnieżdżona wirtualizacja wg polityki z panelu
#    (/var/lib/virthub/security/nested-policy):
#      auto  — włączona tylko, gdy działające jądro ma poprawki znanych ucieczek
#              (numery CVE w changelogu pakietu jądra), w przeciwnym razie wyłączona,
#      allow — włączona mimo braku poprawek (decyzja administratora w panelu).
#    VH_ALLOW_NESTED=1 w /etc/virthub-agent/agent.env działa jak „allow”.
#    EPT/NPT zawsze włączone (bez nich KVM używa shadow MMU dla każdej maszyny).
# 2. Aktualizacje jądra i automatyczne poprawki bezpieczeństwa (bez
#    automatycznego restartu — o nim decyduje administrator).
#
# Idempotentne. Wołane przez instalator i każdą aktualizację węzła, a z opcją
# --nested-only (bez apt) przy starcie węzła przed libvirtd — po restarcie na
# załatane jądro zagnieżdżanie włącza się samo — i po zmianie polityki w panelu.
# VH_KERNEL_UPDATES=0 pomija aktualizację jądra.
set -uo pipefail

ok()   { printf '  ✓ %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*"; }

NESTED_ONLY=0
[ "${1:-}" = "--nested-only" ] && NESTED_ONLY=1

AGENT_DIR=/opt/virthub-agent
STATE_DIR=/var/lib/virthub/security
ENV_FILE=/etc/virthub-agent/agent.env
CONF=/etc/modprobe.d/virthub-kvm.conf

ALLOW_NESTED="${VH_ALLOW_NESTED:-}"
KERNEL_UPDATES="${VH_KERNEL_UPDATES:-}"
if [ -r "$ENV_FILE" ]; then
    [ -z "$ALLOW_NESTED" ] && ALLOW_NESTED="$(sed -n 's/^VH_ALLOW_NESTED=//p' "$ENV_FILE" | tail -n1)"
    [ -z "$KERNEL_UPDATES" ] && KERNEL_UPDATES="$(sed -n 's/^VH_KERNEL_UPDATES=//p' "$ENV_FILE" | tail -n1)"
fi
ALLOW_NESTED="${ALLOW_NESTED:-0}"
KERNEL_UPDATES="${KERNEL_UPDATES:-1}"

# Plik zapisuje agent (bez roota) — bierzemy z niego tylko znane słowo.
POLICY="$(head -c 16 "$STATE_DIR/nested-policy" 2>/dev/null | tr -cd 'a-z')"
[ "$ALLOW_NESTED" = "1" ] && POLICY=allow
case "$POLICY" in allow|auto) ;; *) POLICY=auto ;; esac

# Czy działające jądro ma poprawki ucieczek związanych z zagnieżdżaniem
# (kod 0 — tak, 1 — nie, 2 — nie dotyczy tej architektury).
kernel_patched() {
    local py="$AGENT_DIR/.venv/bin/python"
    [ -x "$py" ] || py="$(command -v python3)"
    [ -n "$py" ] || return 1
    (cd "$AGENT_DIR" 2>/dev/null && "$py" -m agent.host_security --kernel-patched >/dev/null 2>&1)
}

WANT_NESTED=0
if [ "$POLICY" = "allow" ] || kernel_patched; then
    WANT_NESTED=1
fi

# --- zagnieżdżona wirtualizacja i shadow paging -------------------------------

running_vms() {
    command -v virsh >/dev/null 2>&1 || { echo 0; return; }
    virsh -c qemu:///system list --name 2>/dev/null | grep -c . || true
}

# Inne pliki modprobe.d mogły ustawić nested albo wyłączyć EPT/NPT — wyłączamy
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

# Stan modułu dopasowujemy od razu, gdy nie działa żadna maszyna; inaczej po restarcie.
apply_nested() {
    local want="$1" mod param current
    for mod in kvm_intel kvm_amd; do
        param="/sys/module/$mod/parameters/nested"
        [ -r "$param" ] || continue
        case "$(cat "$param")" in 1|Y|y) current=1 ;; *) current=0 ;; esac
        [ "$current" = "$want" ] && continue
        if [ "$(running_vms)" = "0" ] && modprobe -r "$mod" 2>/dev/null && modprobe "$mod"; then
            ok "Moduł $mod przeładowany z nowym ustawieniem zagnieżdżania"
        else
            warn "Zmiana zagnieżdżania zadziała po restarcie węzła (działają maszyny — modułu $mod nie da się teraz przeładować)."
        fi
    done
}

mkdir -p /etc/modprobe.d
neutralise '(ept|npt)=(0|N|n)'
neutralise 'nested=(0|1|Y|y|N|n)'

if [ "$WANT_NESTED" = "1" ]; then
    if [ "$POLICY" = "allow" ]; then
        reason="decyzja administratora w panelu — jądro może nie mieć poprawek"
        warn "Zagnieżdżona wirtualizacja WŁĄCZONA decyzją administratora — jądro bez poprawek jest narażone na ucieczki z maszyn."
    else
        reason="działające jądro ma poprawki znanych ucieczek z maszyn (Januscape, Zapscape)"
        ok "Jądro ma poprawki znanych ucieczek — zagnieżdżona wirtualizacja włączona"
    fi
    {
        echo "# VirtHub: zagnieżdżona wirtualizacja WŁĄCZONA — $reason."
        echo "# Polityką steruje panel: Infrastruktura → Bezpieczeństwo."
        echo "options kvm_intel nested=1"
        echo "options kvm_amd nested=1"
    } > "$CONF"
    apply_nested 1
else
    {
        echo "# VirtHub: zagnieżdżona wirtualizacja wyłączona — jądro nie ma poprawek znanych"
        echo "# ucieczek z maszyn do hosta przez shadow MMU KVM (Januscape, Zapscape). Włączy się"
        echo "# sama po aktualizacji jądra i restarcie; wcześniej można ją włączyć w panelu:"
        echo "# Infrastruktura → Bezpieczeństwo."
        echo "options kvm_intel nested=0"
        echo "options kvm_amd nested=0"
    } > "$CONF"
    ok "Zagnieżdżona wirtualizacja wyłączona (jądro bez poprawek)"
    apply_nested 0
fi

[ "$NESTED_ONLY" = "1" ] && exit 0

# --- usługa roota: polityka z panelu i start węzła -------------------------------

if command -v systemctl >/dev/null 2>&1; then
    install -d -m 0755 /usr/local/lib/virthub
    [ -f "$AGENT_DIR/scripts/harden-host.sh" ] \
        && install -m 0755 "$AGENT_DIR/scripts/harden-host.sh" /usr/local/lib/virthub/harden-host.sh
    if id virthub >/dev/null 2>&1; then
        install -d -m 0755 -o virthub "$STATE_DIR"
    else
        install -d -m 0755 "$STATE_DIR"
    fi
    {
        echo "[Unit]"
        echo "Description=VirtHub — zmiana polityki zagnieżdżonej wirtualizacji z panelu"
        echo
        echo "[Path]"
        echo "PathExists=$STATE_DIR/request"
        echo "Unit=virthub-harden.service"
        echo
        echo "[Install]"
        echo "WantedBy=multi-user.target"
    } > /etc/systemd/system/virthub-harden.path
    {
        echo "[Unit]"
        echo "Description=VirtHub — zagnieżdżona wirtualizacja wg jądra i polityki (ochrona przed ucieczkami z maszyn)"
        echo "# Przy starcie przed libvirtd: moduł KVM da się przeładować, zanim ruszą maszyny."
        echo "Before=libvirtd.service virtqemud.service virthub-agent.service"
        echo "After=local-fs.target"
        echo
        echo "[Service]"
        echo "Type=oneshot"
        echo "ExecStartPre=/bin/rm -f $STATE_DIR/request"
        echo "ExecStart=/bin/bash /usr/local/lib/virthub/harden-host.sh --nested-only"
        echo
        echo "[Install]"
        echo "WantedBy=multi-user.target"
    } > /etc/systemd/system/virthub-harden.service
    systemctl daemon-reload >/dev/null 2>&1 || true
    systemctl reset-failed virthub-harden.path virthub-harden.service >/dev/null 2>&1 || true
    systemctl enable virthub-harden.service virthub-harden.path >/dev/null 2>&1 || true
    systemctl restart virthub-harden.path >/dev/null 2>&1 || true
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
        printf '%s\n' 'APT::Periodic::Update-Package-Lists "1";' 'APT::Periodic::Unattended-Upgrade "1";' \
            > /etc/apt/apt.conf.d/20auto-upgrades
        printf '%s\n' '// VirtHub: poprawki bezpieczeństwa automatycznie, bez restartu węzła z maszynami klientów.' \
            'Unattended-Upgrade::Automatic-Reboot "false";' > /etc/apt/apt.conf.d/52virthub-unattended
        ok "Automatyczne poprawki bezpieczeństwa włączone (bez automatycznego restartu)"
    fi
fi

running="$(uname -r)"
newest="$(ls -1 /boot/vmlinuz-* 2>/dev/null | sed 's|/boot/vmlinuz-||' | sort -V | tail -n1)"
if [ -e /run/reboot-required ] || { [ -n "$newest" ] && [ "$newest" != "$running" ]; }; then
    warn "Zainstalowane jest nowsze jądro (${newest:-?}) niż działające ($running) — poprawki zadziałają po restarcie węzła; zagnieżdżanie włączy się wtedy samo."
fi
exit 0
