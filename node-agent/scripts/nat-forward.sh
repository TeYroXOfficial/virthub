#!/bin/sh
# Przepuszcza ruch maszyn przez łańcuch FORWARD iptables.
#
# ufw (DEFAULT_FORWARD_POLICY="DROP") i Docker ustawiają politykę FORWARD na
# DROP. Pakiety przekierowane na maszynę (DNAT z bloku portów) i jej ruch
# wychodzący idą właśnie tym hakiem — bez tych reguł konsola działa, a SSH
# przez port NAT i internet w maszynie już nie. Reguła „accept" we własnej
# tabeli nftables nie wystarczy: każdy łańcuch na haku musi pakiet przepuścić.
#
# Docker (aplikacje) ładuje też br_netfilter — wtedy nawet ruch mostkowany
# na mostku z kartą fizyczną (maszyny z publicznym adresem) trafia do
# FORWARD i bez reguły dla mostka maszyny straciłyby sieć. Zaporę maszyn
# i anty-spoofing i tak robi tabela bridge agenta.
#
# Uruchamiane przez systemd przed startem agenta (ExecStartPre, jako root).
# Idempotentne; bez iptables na węźle nie robi nic.
NAT_BRIDGE="${VH_NAT_BRIDGE:-vhnat0}"
BRIDGE="${VH_BRIDGE:-br0}"

command -v iptables >/dev/null 2>&1 || exit 0

allow() {
    iptables -w -C FORWARD "$@" 2>/dev/null || iptables -w -I FORWARD 1 "$@"
}

# Z maszyn za NAT-em — dokądkolwiek.
allow -i "$NAT_BRIDGE" -j ACCEPT
# Do maszyn za NAT-em — tylko odpowiedzi i ruch przekierowany z bloku portów
# (DNAT), nie dowolny ruch routowany do sieci prywatnej.
allow -o "$NAT_BRIDGE" -m conntrack --ctstate RELATED,ESTABLISHED,DNAT -j ACCEPT
# Ruch mostkowany maszyn z publicznymi adresami (przy br_netfilter).
allow -i "$BRIDGE" -o "$BRIDGE" -j ACCEPT
