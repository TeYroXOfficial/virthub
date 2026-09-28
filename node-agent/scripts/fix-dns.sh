#!/usr/bin/env bash
#
# Naprawia wolny albo niedziałający DNS hosta.
#
# Typowy przypadek: Debian z ifupdown na Hetznerze — instalator węzła zamienia
# eth0 na most br0, a serwery DNS były wpisane w strofie eth0
# (dns-nameservers). W /etc/resolv.conf zostaje wtedy serwer, który nie
# odpowiada, i każde rozwiązanie nazwy czeka ~5 s (panel: „cURL error 28:
# Resolving timed out”, kontenery: „Could not resolve host”).
#
# Skrypt nic nie zmienia, gdy DNS działa szybko. W przeciwnym razie zostawia
# serwery, które odpowiadają, usuwa martwe i dopisuje 1.1.1.1 i 9.9.9.9.
# Bezpieczny do wielokrotnego uruchamiania (wywołuje go aktualizacja węzła).
#
set -uo pipefail

FALLBACK="${VH_DNS_FALLBACK:-1.1.1.1 9.9.9.9}"
TEST_HOSTS="deb.debian.org api.modrinth.com"

ok()   { printf '  ✓ %s\n' "$*"; }
warn() { printf '  ! %s\n' "$*"; }

# Czas rozwiązania nazwy w ms (albo 99999, gdy się nie udało).
lookup_ms() {
    local start end
    start=$(date +%s%N)
    timeout 6 getent ahosts "$1" >/dev/null 2>&1 || { echo 99999; return; }
    end=$(date +%s%N)
    echo $(( (end - start) / 1000000 ))
}

dns_slow() {
    local host ms
    for host in $TEST_HOSTS; do
        ms=$(lookup_ms "$host")
        [ "$ms" -gt 1500 ] && return 0
    done
    return 1
}

if ! dns_slow; then
    ok "DNS hosta działa szybko"
    exit 0
fi

warn "DNS hosta jest wolny albo nie działa — naprawiam"

# Które z obecnych serwerów odpowiadają (zapytanie UDP, 1,5 s)?
current=$(awk '/^nameserver/ {print $2}' /etc/resolv.conf 2>/dev/null | tr '\n' ' ')
alive=$(python3 - $current <<'PYEOF'
import random, socket, struct, sys

def answers(server):
    family = socket.AF_INET6 if ":" in server else socket.AF_INET
    qid = random.randint(0, 0xFFFF)
    name = b"".join(bytes([len(p)]) + p.encode() for p in "deb.debian.org".split("."))
    packet = struct.pack(">HHHHHH", qid, 0x0100, 1, 0, 0, 0) + name + b"\0" + struct.pack(">HH", 1, 1)
    try:
        with socket.socket(family, socket.SOCK_DGRAM) as s:
            s.settimeout(1.5)
            s.sendto(packet, (server, 53))
            data, _ = s.recvfrom(4096)
            return struct.unpack(">H", data[:2])[0] == qid and (data[3] & 0x0F) == 0
    except OSError:
        return False

print(" ".join(s for s in sys.argv[1:] if not s.startswith("127.") and answers(s)))
PYEOF
)

servers=""
for s in $alive $FALLBACK; do
    case " $servers " in *" $s "*) ;; *) servers="$servers $s" ;; esac
done
servers="${servers# }"
dead=""
for s in $current; do
    case " $alive " in *" $s "*) ;; *) dead="$dead $s" ;; esac
done
[ -n "$dead" ] && warn "Nie odpowiadają:${dead}"

target=$(readlink -f /etc/resolv.conf 2>/dev/null || echo /etc/resolv.conf)
if [ "$target" = /run/systemd/resolve/stub-resolv.conf ] && systemctl is-active --quiet systemd-resolved; then
    # systemd-resolved: serwery w drop-inie, stub 127.0.0.53 zostaje.
    mkdir -p /etc/systemd/resolved.conf.d
    printf '[Resolve]\nDNS=%s\nFallbackDNS=1.0.0.1 149.112.112.112\n' "$servers" > /etc/systemd/resolved.conf.d/virthub-dns.conf
    systemctl restart systemd-resolved
    ok "systemd-resolved: DNS=$servers"
else
    cp -aL /etc/resolv.conf "/etc/resolv.conf.virthub-$(date +%s)" 2>/dev/null || true
    # Dowiązanie (np. resolvconf) zastępujemy zwykłym plikiem — inaczej
    # zmiana zniknęłaby przy następnym podniesieniu interfejsu.
    [ -L /etc/resolv.conf ] && rm -f /etc/resolv.conf
    {
        echo "# VirtHub: fix-dns.sh — martwe serwery usunięte, zapasowe dopisane"
        for s in $servers; do echo "nameserver $s"; done
        echo "options timeout:2 attempts:2"
    } > /etc/resolv.conf
    ok "/etc/resolv.conf: $servers"
fi

if dns_slow; then
    warn "DNS dalej jest wolny — sprawdź, czy firewall nie blokuje wychodzącego UDP/TCP 53."
    exit 1
fi
ok "DNS naprawiony"
