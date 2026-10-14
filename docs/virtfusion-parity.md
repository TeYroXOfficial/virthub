# Plan: funkcje na poziomie VirtFusion

Lista funkcji z https://www.virtfusion.com/features zestawiona z tym, co VirtHub już ma.
Każdy etap to osobny PR (z testami i tłumaczeniami). Kolejność wynika z tego, co
najbardziej się przyda i co da się sensownie sprawdzić bez prawdziwego hypervisora.

## Już jest

| VirtFusion | VirtHub |
|---|---|
| Cykl życia VM: zasilanie, reinstalacja, metryki na żywo, REST API | ✓ (Sanctum API, wykresy, konsola noVNC) |
| Szablony OS z cloud-init, Windows z optymalizacjami Hyper-V | ✓ (`domain_xml.HYPERV`, hypervclock, Windows build) |
| Biblioteka ISO, montowanie ISO | ✓ (administrator dodaje ISO z adresu URL) |
| NAT, pule IP, IPv4/IPv6, rDNS, MAC, śledzenie adresów | ✓ |
| Anty-spoofing i firewall VM | ✓ (`agent/network.py`) |
| Migawki | ✓ |
| Rozliczanie godzinowe z salda, wbudowany billing, tickety | ✓ |
| Tryb serwisowy hypervisora, migracje | ✓ |
| Logi audytu, uprawnienia administratorów | ✓ |
| Odsprzedaż usług innych dostawców | ✓ (addony, np. Onidel) |

## Etapy

1. ✅ **Bezpieczeństwo konta.**
   - 2FA (TOTP) z kodami zapasowymi.
   - Lista aktywnych sesji z wylogowaniem.
   - Tokeny API z uprawnieniami (zakresami) w panelu klienta.
   - Zaufane adresy IP dla kont administratorów.
2. ✅ **Webhooki.**
   - Podpisane (HMAC-SHA256) powiadomienia o zdarzeniach: serwer utworzony/zbudowany/usunięty/zasilanie/zmiana IP/migracja, użytkownik utworzony/zmieniony/usunięty, kopie, mailouty, oraz „wszystkie zdarzenia”.
   - Historia dostarczeń, ponowienia.
3. ✅ **Mailouty.**
   - Masowa wysyłka e-maili według szablonu ze zmiennymi.
   - Grupy odbiorców: wszyscy, z aktywnymi serwerami, na wybranym hypervisorze, z wybranym produktem.
   - Kolejka i postęp wysyłki.
4. ✅ **Provisionery.**
   - Skrypty pierwszego uruchomienia (shebang, opis, miejsce zapisu) przypięte do szablonów.
   - Zmienne serwera (`{{hostname}}`, `{{ipv4}}`…) wstawiane przez cloud-init.
   - Ścieżki logów widoczne w panelu.
5. **Profile filtrowania sieci.**
   - Profile brak / libvirt (nwfilter) / natywny.
   - Blokada IPv6 RA i DHCPv6.
   - Ustawiane globalnie albo per VM.
   - Własne reguły w JSON.
6. **Własne ISO klienta.** Klient podaje adres ISO; agent pobiera je do katalogu VM, a po odmontowaniu plik jest usuwany.
7. **Alerty.**
   - Progi: CPU, dysk, transfer, hypervisor offline, nieudana kopia.
   - Powiadomienie e-mailem i webhookiem.
   - Stan zdrowia hypervisorów.
8. **Elevate.** Nakładka (overlay) na wybrane dyski, po zmianach „zatwierdź” albo „odrzuć”.
9. **Przekazywanie PCI/GPU.** Zasoby hypervisora (hostdev XML), przypinane przez pakiet albo API, z widokiem, która VM je trzyma.
10. **Samoobsługa (resource packs).**
    - Klient sam tworzy i usuwa VM w ramach pakietu zasobów (CPU/RAM/dysk/IP), rozliczanie godzinowe.
    - Maszyny „tradycyjne” chronione przed usunięciem z samoobsługi.
11. **Kopie zapasowe na zewnętrzne cele.**
    - Cele: S3, SFTP/SSH.
    - Kompresja, retencja i przycinanie.
    - Kopie przyrostowe (dirty bitmaps QEMU), przywracanie.
12. **Sieci zaawansowane.** VLAN (tagowane mosty), sieci izolowane i routowane podsieci IPv4/IPv6, karty sieciowe per VM.
13. **Magazyny danych.** Datastore'y przypięte do hypervisorów i pakietów, sterownik Ceph RBD, cienkie przydzielanie, metryki.
14. **Moduły billingowe.** Moduł WHMCS i Blesta korzystający z `/api/billing`.
15. **Disaster recovery hypervisora.**
    - Kopia dysków, konfiguracji i stanu hypervisora na lokalny dysk albo S3.
    - Odtworzenie na zastępczym hoście.
16. **Migracje VM między hypervisorami.** Na zimno (kopiowanie dysku) i na żywo (libvirt migrate z kopiowaniem dysku), z wyborem docelowego węzła i webhookiem `server.migrated`.
