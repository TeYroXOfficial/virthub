# Plan projektu

Pełna specyfikacja z fazami, modelem danych i kryteriami akceptacji:
<https://claude.ai/code/artifact/a76c7c2c-15fd-4d51-84f1-16c7a610e87c>

Poniżej skrót fazowy, do którego odwołuje się tabela stanu w `README.md`.

| Faza | Zakres | Kryterium akceptacji (DoD) |
|---|---|---|
| 0 | Fundament: monorepo, CI, środowisko dev, szkielety obu komponentów | Środowisko wstaje jedną komendą; agent odpowiada na `/ping` |
| 1 | Cykl życia VPS na jednym hypervisorze, panel klienta i admina | Klient zamawia VPS z panelu i po ~60 s ma działający serwer |
| 2 | Pule IP, firewall, snapshoty, konsola noVNC | Klient otwiera konsolę bez SSH i przywraca snapshot z panelu |
| 3 | Telemetria, alerty, powiadomienia e-mail | Panel pokazuje wykres zużycia z 30 dni, odświeżany co ≤5 min |
| 4 | API publiczne, integracja rozliczeniowa, SSO | Webhook faktury → automatyczny provisioning end-to-end |
| 5 | Multi-node: dobór węzła, mTLS | Dodanie drugiego węzła bez przestoju i bez zmian w kodzie |
| 6 | Utwardzenie, white-label, testy obciążeniowe | 50 równoległych provisioningów bez błędów |

## Decyzje architektoniczne podjęte przy realizacji

Rzeczy, które w planie były otwarte, a przy pisaniu kodu wymagały rozstrzygnięcia.

**Panel w Blade zamiast Vue + Inertia.** Plan zakładał SPA. Zbudowany został
panel renderowany po stronie serwera, bez kroku budowania assetów — dzięki temu
system wstaje na świeżym serwerze bez Node.js i npm. Sterowanie maszyną w
przeglądarce i tak idzie przez to samo API, którego używa integracja rozliczeniowa,
więc przejście na SPA nie wymaga zmian w backendzie.

**Dwie drogi dostarczenia wyniku zadania.** Plan przewidywał callback od agenta.
Sam callback jest zawodny (zgubiony pakiet zostawia maszynę w stanie „building"
na zawsze), więc doszło cykliczne uzgadnianie stanu jako siatka bezpieczeństwa.

**Lokalna kolejka SQLite w agencie.** Nie było w planie. Bez niej przerwanie
łączności w trakcie provisioningu zostawia maszynę w stanie nieokreślonym —
agent nie wie, czy dokończyć, a panel nie wie, co się stało.

**Deterministyczne nazwy interfejsów sieciowych.** Libvirt domyślnie nadaje
`vnet0`, `vnet1` w kolejności uruchamiania. Po restarcie hosta numeracja potrafi
się przesunąć, a reguły firewalla trafiłyby wtedy w cudzą maszynę. Agent wymusza
nazwę `vh{server_id}`.

**Rezerwacja zasobów przed provisioningiem, nie po.** Dwa równoległe zamówienia
widziałyby to samo wolne miejsce. Rezerwacja idzie w transakcji z blokadą wiersza
hypervisora, a nieudany provisioning zwalnia ją w rollbacku.

**Zwolnienie adresu IP dopiero po potwierdzeniu usunięcia przez hypervisor.**
Wcześniejsze zwolnienie oznaczałoby, że kolejny klient dostaje adres wciąż
podpięty do cudzej, działającej maszyny.
