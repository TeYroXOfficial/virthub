#!/bin/sh
# Przepuszcza ruch maszyn za NAT-em przez łańcuch FORWARD iptables.
#
# ufw (DEFAULT_FORWARD_POLICY="DROP") i Docker ustawiają politykę FORWARD na
# DROP. Pakiety przekierowane na maszynę (DNAT z bloku portów) i jej ruch
# wychodzący idą właśnie tym hakiem — bez tych reguł konsola działa, a SSH
# przez port NAT i internet w maszynie już nie. Reguła „accept" we własnej
# tabeli nftables nie wystarczy: każdy łańcuch na haku musi pakiet przepuścić.
#
# Uruchamiane przez systemd przed startem agenta (ExecStartPre, jako root).
# Idempotentne; bez iptables na węźle nie robi nic.
BRIDGE="${VH_NAT_BRIDGE:-vhnat0}"

command -v iptables >/dev/null 2>&1 || exit 0

allow() {
    iptables -w -C FORWARD "$@" 2>/dev/null || iptables -w -I FORWARD 1 "$@"
}

# Z maszyn — dokądkolwiek (anty-spoofing i zapora maszyny działają w tabeli bridge).
allow -i "$BRIDGE" -j ACCEPT
# Do maszyn — tylko odpowiedzi i ruch przekierowany z bloku portów (DNAT),
# nie dowolny ruch routowany do sieci prywatnej.
allow -o "$BRIDGE" -m conntrack --ctstate RELATED,ESTABLISHED,DNAT -j ACCEPT
