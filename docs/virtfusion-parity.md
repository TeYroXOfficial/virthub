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
5. ✅ **Profile filtrowania sieci.**
   - Profile brak / libvirt (nwfilter) / natywny.
   - Blokada IPv6 RA i DHCPv6.
   - Ustawiane globalnie albo per VM.
   - Własne reguły w JSON.
6. ✅ **Własne ISO klienta.** Klient podaje adres ISO; agent pobiera je do katalogu VM, a po odmontowaniu plik jest usuwany.
7. ✅ **Alerty.**
   - Progi: CPU, dysk, transfer, hypervisor offline, nieudana kopia.
   - Powiadomienie e-mailem i webhookiem.
   - Stan zdrowia hypervisorów.
8. ✅ **Elevate.** Nakładka (overlay) na wybrane dyski, po zmianach „zatwierdź” albo „odrzuć”.
9. ✅ **Przekazywanie PCI/GPU.** Zasoby hypervisora (hostdev XML), przypinane przez pakiet albo API, z widokiem, która VM je trzyma.
10. ✅ **Samoobsługa (resource packs).**
    - Klient sam tworzy i usuwa VM w ramach pakietu zasobów (CPU/RAM/dysk/IP), rozliczanie godzinowe.
    - Maszyny „tradycyjne” chronione przed usunięciem z samoobsługi.
11. ✅ **Kopie zapasowe na zewnętrzne cele.**
    - Cele: S3, SFTP/SSH.
    - Kompresja, retencja i przycinanie.
    - Kopie przyrostowe (dirty bitmaps QEMU), przywracanie.
12. ✅ **Sieci zaawansowane.** VLAN (tagowane mosty), routowane podsieci IPv4/IPv6, sieci izolowane i wiele kart (etap 19).
13. ✅ **Magazyny danych.** Datastore'y (katalog qcow2 cienki/gruby, Ceph RBD) przypięte do hypervisorów i pakietów, wybór węzła z magazynem przy zamówieniu, pojemność odczytywana z węzłów co 15 minut. Dyski w Cephie: migawki RBD; Elevate i kopie zewnętrzne tylko dla magazynów katalogowych.
14. ✅ **Moduły billingowe.** Moduły WHMCS, Blesta i Paymenter (`integrations/`, do pobrania w panelu) na `/api/v1/billing`: zakładanie, zawieszanie, usuwanie, zmiana pakietu i sterowanie serwerem z panelu billingu (zasilanie, konsola, reinstalacja, reset hasła, transfer, SSO), PL/EN.
15. ✅ **Disaster recovery hypervisora.** (Hypervisor → Disaster recovery: plan kopii dla maszyn węzła bez planu, zrzuty konfiguracji na cel kopii, odtworzenie na zastępczym hoście z przeniesieniem pul IP)
    - Kopia dysków, konfiguracji i stanu hypervisora na lokalny dysk albo S3.
    - Odtworzenie na zastępczym hoście.
16. ✅ **Migracje VM między hypervisorami.** Na zimno (kopiowanie dysku) i na żywo (libvirt migrate z kopiowaniem dysku), z wyborem docelowego węzła i webhookiem `server.migrated`.
17. ✅ **Kopie przyrostowe.** Checkpointy libvirt / dirty bitmaps QEMU (backupBegin, tylko zmienione bloki), łańcuch pełna + przyrostowe z „pełna co N”, retencja z zachowaniem zależnych ogniw, przywracanie i DR ze spłaszczeniem łańcucha (qemu-img rebase + convert). Maszyna zatrzymana albo brak checkpointu rodzica → automatycznie kopia pełna.
18. ✅ **Dokumentacja API.** Strona `/api-docs`: OpenAPI 3 dla wszystkich endpointów, opisy w każdym języku panelu, przykłady (curl, PHP, Python, JavaScript, Go), wbudowana konsola do testowania zapytań (jak Postman, z historią) i eksport kolekcji Postman.
19. ✅ **Sieci izolowane i wiele kart.** Sieci prywatne klienta: osobny mostek `vhp<id>` na węźle bez łącza w górę i bez adresu hosta, adresacja z zakresów prywatnych z automatycznym przydziałem; do 7 kart dodatkowych na maszynę KVM (limit `VH_PRIVATE_NICS_PER_SERVER`, domyślnie 4), dołączanie/odłączanie na żywo, adres przez cloud-init/cloudbase-init przy tworzeniu i reinstalacji, API `/servers/{server}/private-networks`. Sieć działa w obrębie węzła — migracja wymaga odłączenia kart, DR przenosi sieci z maszynami.

## Luki względem VirtFusion 7.1 (porównanie z 15.10.2026)

Źródło: strona https://www.virtfusion.com/features i informacje o wydaniach 5.0–7.1.0
(Backup Manager z 7.0, nowe API z 7.1). Każdy etap to osobny PR.

20. ✅ **Dyski i sprzęt maszyny.** Do 7 dodatkowych dysków (vdb–vdh; w pakiecie albo ręcznie przez personel i API), powiększanie każdego dysku na żywo (blockResize), rezerwacja miejsca na węźle i cofnięcie zmiany, gdy węzeł odmówi. Sprzęt: VirtIO / VirtIO SCSI z wątkiem I/O, tryb I/O native / threads / io_uring, BIOS/UEFI (klient może przełączyć), vTPM 2.0 (swtpm), model CPU (host-passthrough, host-model, modele QEMU), cpuset — dziedziczenie maszyna → szablon/węzeł → domyślne. Dodatkowe dyski nie są objęte migawkami, kopiami ani migracją (migracja jest blokowana); przy DR wracają puste.
21. ✅ **Tryb ratunkowy i ustawienia klienta.** Rescue mode: start z czystego systemu (nakładka na szablon oznaczony jako ratunkowy, domyślnie Debian/Ubuntu KVM) z tymi samymi adresami, dysk maszyny jako /dev/vdi, hasło roota w panelu i API, powrót do dokładnie tej samej definicji; w trybie ratunkowym zablokowane reinstalacja, kopie, Elevate, migracja i zmiany dysków. Klient: odłączanie dysku cloud-init (instalacja z ISO), zmiana nazwy hosta (w systemie przez qemu-guest-agent), wybór własnego adresu z puli IPv6, plik wymiany w kreatorze zamówienia (VH_SWAP_SELECTION).
22. **Transfer.** Dławienie prędkości zamiast blokady po limicie, kierunek liczenia (wejście / wyjście / oba), dokupowane pakiety transferu, polityka na węźle, w pakiecie i na maszynie, limit przepustowości karty, webhooki włączenia i zdjęcia dławienia, jednostki dziesiętne lub binarne.
23. **Kopie — Backup Manager.** Cele FTP/FTPS, Rclone (ponad 35 chmur) i Proxmox Backup Server. Szyfrowanie i deduplikacja, retencja GFS, kopie różnicowe, dni tygodnia w harmonogramie. Spójność przez qemu-guest-agent (fsfreeze) z raportem, czy system plików był zamrożony. Kopie dysków w Cephie (migawki RBD). Przywracanie na inną maszynę i do punktu w czasie, drzewo kopii dla klienta, odzyskiwanie pojedynczych plików (montowanie kopii). Webhooki kopii zbiorczych i przywracania.
24. **Sieć — topologie.** Open vSwitch, macvtap/direct, sieci prywatne między węzłami (VXLAN), zapora na każdą kartę osobno.
25. **Magazyny zdalne.** Datastore'y na NFS, CephFS, CIFS/SMB i iSCSI (montowane przez agenta), StorPool, Lightbits NVMe/TCP.
26. **Windows.** Ustawienia Hyper-V na trzech poziomach (węzeł → szablon → maszyna) i profil „Direct RDP” (gotowy RDP, dane logowania mailem).
27. **Konsola VNC.** Nagrywanie do WebM, wpisywanie tekstu i zapisane fragmenty, presety jakości, tryb tylko do podglądu, Picture-in-Picture, wirtualna klawiatura i panel klawiszy, zrzut ekranu.
28. **Bezpieczeństwo kont — rozszerzenie.** Ponowne potwierdzenie (step-up) przy operacjach wrażliwych, wiele metod 2FA (WebAuthn / passkey), wymuszanie 2FA także dla klientów, przypięcie sesji do IP i przeglądarki, ważność i limity zapytań tokenów API.
29. **Migracje — rozszerzenie.** Postęp krok po kroku, wznawianie, anulowanie i cofanie nieudanej migracji, lista zgodnych węzłów docelowych, przesył po alternatywnym adresie węzła.
30. **Operacje i administracja.** Audyt przydziałów IP z eksportem CSV/JSON/YAML, kolejność pul IP na węźle, strategie rozmieszczania maszyn w grupie, metryki węzła i status panelu w API, kopia systemowa panelu, skrypty przed i po DR, udostępnianie maszyny innym użytkownikom, zmiana właściciela, węzły ARM.
31. **Webhooki i self-service — rozszerzenie.** Uwierzytelnianie webhooków tokenem Bearer, przykładowe odbiorniki (Slack, Discord, Telegram, Pushover, Google Chat), godzinowe rozliczanie dodatków (adresy IP, GPU), transfer liczony łącznie dla pakietu zasobów, zmienne limity pakietów.
