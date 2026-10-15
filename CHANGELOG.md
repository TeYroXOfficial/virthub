# Changelog

Wersje zgodne z [semver](https://semver.org/lang/pl/): PATCH — poprawki,
MINOR — nowe funkcje bez zmian niekompatybilnych, MAJOR — zmiany wymagające
uwagi przy aktualizacji. Sekcja wersji trafia jako opis wydania do portalu.

## [1.0.0] — 2026-10-15

Pierwsze wydanie dystrybuowane przez portal VirtHub.

- Panel: VPS na KVM (Linux i Windows), kontenery LXC, aplikacje i serwery gier w Dockerze.
- Billing: sklep, portfel, faktury, Stripe i PayPal, pakiety zasobów, dodatki godzinowe.
- Migracje na żywo, kopie zapasowe na S3/SFTP, magazyny zdalne (Ceph, NFS, iSCSI).
- Sieć: IPv4/IPv6, NAT, sieci prywatne, VLAN, rDNS, limity i dławienie transferu.
- Bezpieczeństwo: 2FA, klucze dostępu, audyt CVE węzłów, podpisane aktualizacje.
- Monitoring hostów z ruchem sieciowym każdej maszyny, webhooki, API.
- Aktualizacje panelu i węzłów z portalu: wersje semver, kanały stable/beta, podpis Ed25519.
