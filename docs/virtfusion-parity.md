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
22. ✅ **Transfer.** Polityka transferu na węźle, w pakiecie i na maszynie (dziedziczenie → ustawienia globalne): kierunek liczenia (wejście / wyjście / oba), akcja po limicie — blokada (zawieszenie) albo dławienie do N Mbit/s — i prędkość dławienia. Pakiety transferu dokupione na okres (panel i API), stała prędkość portu z pakietu (tc w libvirt, limits.ingress/egress w Incusie, na żywo), webhooki `server.traffic_throttled` / `server.traffic_unthrottled`, jednostki dziesiętne albo binarne (VH_TRAFFIC_UNITS), API `/admin/servers/{server}/traffic`.
23. ✅ **Kopie — Backup Manager.** Cele FTP/FTPS, Rclone (ponad 35 chmur; konfiguracja tylko na czas operacji) i Proxmox Backup Server (proxmox-backup-client, obraz surowy — PBS deduplikuje i pomija zera). Szyfrowanie AES-256-GCM na węźle przed wysyłką (ramki z kontrolą kolejności i ucięcia), retencja GFS (dzienne / tygodniowe / miesięczne obok ostatnich N), kopie różnicowe obok przyrostowych, dni tygodnia w harmonogramie. Spójność przez qemu-guest-agent (fsfreeze tylko na chwilę migawki) z oznaczeniem każdej kopii. Pełne kopie dysków w Cephie (eksport migawki RBD, przywracanie przez import obok i zamianę nazw). Przywracanie na inną maszynę (personel) i do dowolnego punktu łańcucha, drzewo kopii dla klienta. Poza zakresem: odzyskiwanie pojedynczych plików z kopii i kopie dodatkowych dysków (vdb–vdh).
24. ✅ **Sieć — topologie.** Sieci prywatne rozpięte na grupę węzłów: tunel VXLAN (unicast, bez uczenia się adresów, MTU 1450 w karcie i cloud-init) między adresami węzłów w sieci wewnętrznej, lista węzłów w tunelu odświeżana przy podłączaniu, odłączaniu i migracji, konfiguracja odtwarzana po restarcie — migracja z kartami prywatnymi w obrębie grupy. Zapora na każdej karcie (osobny łańcuch nftables dla karty prywatnej z anty-spoofingiem jej adresu). Open vSwitch (VH_NETWORK_BACKEND=ovs: port OVS, VLAN tagiem na porcie, anty-spoofing przepływami OpenFlow; zapora klienta na karcie głównej w tym trybie nie działa) i macvtap/direct (VH_NETWORK_BACKEND=direct, bez filtrowania na hoście — dla zaufanych węzłów); tryb widoczny na stronie węzła.
25. ✅ **Magazyny zdalne.** NFS, SMB/CIFS, CephFS i iSCSI (z CHAP) montowane przez agenta w /var/lib/virthub/mnt/<id> przy pierwszym użyciu i po starcie węzła — dyski to na nich pliki qcow2, więc działają migawki, Elevate i kopie przyrostowe; hasła i klucze tylko w plikach tymczasowych 0600. StorPool (klon migawki szablonu, migawki, `attach … here`) i Lightbits NVMe/TCP (lbcli z JWT w środowisku, `nvme connect` na adresy danych, ACL z NQN hosta) jako dyski blokowe — eksperymentalne, zgodne z dokumentacją, niesprawdzone na klastrze. Dyski blokowe: pełne kopie zewnętrzne, bez Elevate, migracji z kopiowaniem dysku i DR. Klienci: scripts/setup-remote-storage.sh.
26. ✅ **Windows.** Oświecenia Hyper-V jako profil (wyłączone / standardowe / pełne — z zegarem TSC, IPI, flush TLB, reset i vendor_id / passthrough) dziedziczony maszyna → szablon → węzeł → system (Windows standardowe, Linux wyłączone), działa po wyłączeniu i włączeniu. Profil „Direct RDP” szablonu Windows: po instalacji i reinstalacji mail z adresem, użytkownikiem Administrator i hasłem oraz linkiem do pliku .rdp; plik .rdp (bez hasła) do pobrania na stronie każdej maszyny Windows.
27. ✅ **Konsola VNC.** Pasek narzędzi noVNC: panel klawiszy (Esc, Tab, F1–F12, strzałki, PrtSc…) z przyklejanymi modyfikatorami Ctrl/Alt/Shift/Win i gotowymi skrótami (Ctrl+Alt+F1/F2/F7, Alt+Tab, Alt+F4, Win+R, Ctrl+Shift+Esc), wpisywanie tekstu ze schowka albo okna, zapisane fragmenty użytkownika (szyfrowane w bazie, do 50, dostępne w konsoli każdej maszyny), klawiatura ekranowa na urządzeniach dotykowych, presety jakości (najlepsza / zrównoważona / słabe łącze, zapamiętane w przeglądarce), tryb tylko do podglądu, zrzut ekranu PNG, nagrywanie do WebM (MediaRecorder, zapis lokalny) i obraz w obrazie.
28. ✅ **Bezpieczeństwo kont — rozszerzenie.** Klucze dostępu WebAuthn / passkeys (ES256, RS256, Ed25519; własna weryfikacja bez zewnętrznych bibliotek — wyzwanie, pochodzenie, RP ID, podpis, licznik przeciw klonom): logowanie bez hasła, drugi składnik zamiast kodu TOTP, do 10 kluczy na konto. Ponowne potwierdzenie tożsamości (step-up, hasło / kod / klucz, ważne VH_STEP_UP_MINUTES = 15 min) przed usunięciem i reinstalacją maszyny, tokenami API, kluczami dostępu, przypięciem sesji i zarządzaniem kontami personelu. Wymóg 2FA także dla klientów (bez drugiego składnika tylko Moje konto, tokeny dostają 403). Przypięcie sesji do adresu IP i przeglądarki (opcja konta). Limit zapytań na minutę dla każdego tokenu API (domyślnie VH_API_TOKEN_RATE_LIMIT = 120, nagłówki X-RateLimit-*). Data ważności tokenów była już wcześniej.
29. ✅ **Migracje — rozszerzenie.** Etapy migracji z czasem trwania (payload.steps) i postęp całości z postępem agenta — pobieranie dysku w MB, na żywo przesłane dane, prędkość i przebieg pamięci z libvirt (jobStats) — w panelu postępu maszyny i w API `/admin/servers/{id}/migration`. Anulowanie (przed startem od razu, na żywo przez abortJob w libvirt, eksport i import dochodzą do końca, po czym cel jest sprzątany, a źródło wraca do pracy). Nieudana migracja: wznowienie — na zimno od pobierania dysku, gdy eksport na zatrzymanej maszynie przetrwał, inaczej od początku — albo cofnięcie (sprzątanie celu: domena, dyski, sieć, magazyn; unieważnienie eksportu). Lista zgodnych węzłów z powodem odmowy (panel i API `migration-targets`). Adres do migracji węzła (sieć wewnętrzna) dla przesyłu dysku i połączenia libvirt.
30. ✅ **Operacje i administracja.** Audyt IP (historia przydziałów: maszyna, klient, węzeł, od–do — także po usunięciu maszyny) z eksportem CSV/JSON/YAML; kolejność pul IP (priorytet); strategie rozmieszczania maszyn (upakowanie, rozpraszanie, najmniej maszyn, losowo — domyślna panelu i własna grupy); API `/admin/status` (200/503 do monitoringu) i `/admin/hypervisors/{id}/metrics`; kopia systemowa panelu (baza, .env, storage/app; dobowa, retencja, pobieranie za step-up; odtworzenie w docs/system-backup.md); skrypty przed i po odtworzeniu DR uruchamiane na zastępczym węźle z wynikiem w dzienniku; udostępnianie maszyny innym kontom z wybranymi uprawnieniami; zmiana właściciela (z usługą billingową); węzły ARM (aarch64: maszyna virt z UEFI AAVMF, GICv3, PL011, architektura w raporcie agenta, szablony z architekturą, dobór węzła i blokada migracji między architekturami).
31. **Webhooki i self-service — rozszerzenie.** Uwierzytelnianie webhooków tokenem Bearer, przykładowe odbiorniki (Slack, Discord, Telegram, Pushover, Google Chat), godzinowe rozliczanie dodatków (adresy IP, GPU), transfer liczony łącznie dla pakietu zasobów, zmienne limity pakietów.
