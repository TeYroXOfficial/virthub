# VirtHub

Self-hostowana platforma do zarządzania maszynami wirtualnymi KVM — panel klienta,
panel administratora, agent na hypervisorach i REST API do integracji z systemami
rozliczeniowymi.

Autorska implementacja o zakresie funkcjonalnym zbliżonym do VirtFusion. Nie
zawiera kodu, nazwy ani identyfikacji wizualnej tamtego produktu.

---

## Architektura

Dwa niezależnie wdrażane komponenty:

| Komponent | Technologia | Rola |
|---|---|---|
| `control-plane/` | PHP 8.2+ / Laravel 12 | Panel, API, baza, kolejka zadań, księgowanie zasobów |
| `node-agent/` | Python 3.12 / FastAPI | Jedyny komponent dotykający libvirt i jądra hosta |

Control plane **nigdy nie loguje się na hypervisor po SSH**. Cała komunikacja idzie
przez API agenta, podpisane HMAC-SHA256 na kanonicznej postaci żądania:

```
{timestamp}\n{METODA}\n{ścieżka}\n{sha256(body)}
```

Podpis obejmuje ścieżkę i treść, więc przechwycone żądanie „zatrzymaj VPS 5" nie
da się przerobić na „usuń VPS 9". Znacznik czasu chroni przed odtworzeniem.

### Przepływ zamówienia

```
klient → panel → rezerwacja zasobów i adresu IP (transakcja z blokadą wiersza)
      → kolejka → agent (POST /vm) → qcow2 + cloud-init + libvirt
      → wynik wraca callbackiem albo przez uzgadnianie (virthub:reconcile-jobs)
```

Wynik zadania dociera **dwiema niezależnymi drogami**: agent sam raportuje
callbackiem, a panel dodatkowo dopytuje o zadania wiszące dłużej niż 45 sekund.
Zgubiony pakiet nie zostawia maszyny w stanie „building" na zawsze.

---

## Instalacja na serwerze

Jedno polecenie na serwerze panelu (Debian 12/13, Ubuntu 22.04/24.04):

```bash
curl -sSL https://raw.githubusercontent.com/UZYTKOWNIK/virthub/main/infra/install-panel.sh | sudo bash -s -- --domain panel.twojadomena.pl --repo https://github.com/UZYTKOWNIK/virthub.git
```

Hypervisory dodaje się z panelu: **Administracja → Hypervisory** daje gotowe
polecenie do wklejenia na serwerze z KVM. Oba instalatory niczego nie wymagają
edytować — szczegóły, warianty i instalacja ręczna w
[`docs/wdrozenie.md`](docs/wdrozenie.md).

---

## Uruchomienie środowiska developerskiego

Agent działa w trybie `mock` — tworzy pliki zamiast maszyn, więc cały przepływ
da się przejść na Windows/macOS bez KVM.

### 1. Control plane

```bash
cd control-plane
php ../tools/composer.phar install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

Konta developerskie (hasła jawne, wyłącznie do developmentu):

- `admin@virthub.test` / `haslo-developerskie` — administrator
- `klient@virthub.test` / `haslo-developerskie` — klient

### 2. Node agent

```bash
cd node-agent
python -m venv .venv
.venv/bin/pip install -r requirements.txt   # Windows: .venv\Scripts\pip
cp .env.example .env                        # ustaw VH_AGENT_DRIVER=mock
.venv/bin/uvicorn agent.main:app --host 127.0.0.1 --port 8899
```

Token w `VH_AGENT_TOKEN` musi być **identyczny** z tym zapisanym przy hypervisorze
w panelu (seeder ustawia `dev-token-zmien-w-produkcji`).

### 3. Sprawdzenie łączności

```bash
cd control-plane
php artisan virthub:poll-hypervisors
```

Poprawna odpowiedź: `dev-node: mock, 0 VM, wolne … MB RAM / … GB dysku`.

---

## Testy

```bash
cd control-plane && php artisan test      # 56 testów
cd node-agent && .venv/bin/pytest -q      # 33 testy
```

Cały zestaw działa bez hypervisora i bez sieci zewnętrznej.

---

## Zadania cykliczne

Wymagają działającego schedulera (`php artisan schedule:work` lub wpis w cronie):

| Polecenie | Częstotliwość | Rola |
|---|---|---|
| `virthub:poll-hypervisors` | co minutę | Heartbeat floty i pojemność węzłów |
| `virthub:reconcile-jobs` | co minutę | Dopytanie o wynik zgubionych zadań |
| `virthub:collect-metrics` | co 5 minut | Telemetria działających maszyn |

---

## Stan realizacji

Wobec planu fazowego (`docs/plan.md`):

| Faza | Zakres | Stan |
|---|---|---|
| 0 | Fundament, monorepo, CI, środowisko dev | ✅ gotowe |
| 1 | Cykl życia VPS, panel, rezerwacja zasobów, adresy IP | ✅ gotowe (tryb mock zweryfikowany end-to-end) |
| 2 | Firewall, snapshoty, konsola | 🟡 model danych, API i sterownik gotowe; brakuje proxy WebSocket konsoli |
| 3 | Telemetria i powiadomienia | 🟡 zbieranie i wykresowanie gotowe; brak powiadomień e-mail |
| 4 | API publiczne, integracja rozliczeniowa, SSO | ✅ gotowe (bez wygenerowanej dokumentacji OpenAPI) |
| 5 | Multi-node | 🟡 architektura gotowa (dobór węzła, izolacja sekretów); niesprawdzone na wielu węzłach |
| 6 | Utwardzenie, white-label | 🟡 audyt, RBAC, limity i branding gotowe; brak testów obciążeniowych |

**Czego nie ma i trzeba zrobić przed produkcją:**

1. **Weryfikacja na prawdziwym KVM.** Sterownik libvirt jest napisany, ale
   uruchamiany był wyłącznie w trybie mock — na tej maszynie nie ma hypervisora.
   Pierwszy krok na docelowym serwerze to `VH_AGENT_DRIVER=libvirt` i pełny
   przejazd cyklu życia maszyny.
2. **Proxy konsoli** (websockify + noVNC) — panel wystawia bilety dostępu,
   brakuje komponentu zestawiającego tunel.
3. **Powiadomienia e-mail** — zdarzenia są logowane, ale nikt ich nie wysyła.
4. **Snapshoty maszyn działających** — obecnie wymagają zatrzymania VPS-a.
   Snapshot live wymaga QEMU guest agent w gościu.
5. **IPv6** — schemat bazy jest przygotowany, import puli obsługuje wyłącznie IPv4.

---

## Struktura repozytorium

```
control-plane/          aplikacja Laravel
  app/Domain/           logika domenowa (Agent, Provisioning)
  app/Jobs/             zadania kolejki
  app/Console/Commands/ polecenia cykliczne
node-agent/             usługa FastAPI na hypervisorze
  agent/                sterownik libvirt, storage, sieć, cloud-init
  systemd/              jednostka usługi
shared/api-contracts/   kontrakt między control plane a agentem
infra/                  compose dla dev, playbook wdrożenia agenta
docs/                   plan projektu i decyzje architektoniczne
```

---

## Bezpieczeństwo — decyzje warte zapamiętania

- **Sekrety agentów** leżą w bazie zaszyfrowane (`encrypted` cast). Wyciek dumpu
  bazy nie daje kontroli nad hypervisorami.
- **Anty-spoofing** jest regułą nieusuwalną przez klienta: pakiet wychodzący z
  interfejsu VPS-a musi mieć adres źródłowy przypisany do tego VPS-a.
- **Hasło root** pokazywane jest dokładnie raz i kasowane z bazy przy odczycie.
- **Reguły firewalla** generuje agent z bazy — ręczna zmiana `nftables` na hoście
  zostanie nadpisana. Panel jest źródłem prawdy.
- **Usunięcie maszyny** zwalnia adres IP dopiero po potwierdzeniu przez hypervisor,
  żeby kolejny klient nie dostał adresu wciąż podpiętego do cudzej maszyny.
