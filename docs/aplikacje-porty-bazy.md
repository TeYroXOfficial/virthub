# Aplikacje: porty i bazy danych — plan

## Porty

Aplikacja dostaje przy zamówieniu tyle portów, ile ma jej plan (`app_plans.ports`),
z zakresu ustawionego na węźle (z pominięciem bloków NAT maszyn). Pierwszy port
jest główny — trafia do `SERVER_PORT` i do adresu aplikacji.

**Zakładka „Sieć” w aplikacji** (klient i personel):

- lista portów z adresem `IP_węzła:port`, oznaczeniem głównego i własną notatką
  (np. „query”, „RCON”, „voice”),
- **Dodaj port** — kolejny wolny port węzła, do limitu planu,
- **Ustaw jako główny**, **Usuń** (nie główny i nie ostatni),
- personel: konkretny numer portu i dodawanie ponad limit (limit aplikacji
  `app_servers.port_limit` nadpisuje limit planu).

Zmiana trafia do węzła od razu (specyfikacja aplikacji); kontener dostaje nowe
porty przy następnym starcie — panel pokazuje „wymaga restartu”. Agent nie
potrzebuje zmian: przy starcie porównuje skrót specyfikacji i odtwarza kontener.

## Bazy danych

Model jak w Pterodactylu: administrator dodaje **serwery baz danych** (MySQL /
MariaDB), panel łączy się z nimi kontem administracyjnym i zakłada klientom
bazy oraz użytkowników z dostępem tylko do własnej bazy.

**Tabele**

- `database_hosts` — nazwa, host i port (połączenie panelu), adres dla klientów
  (`public_host`, np. publiczny IP węzła), konto administracyjne (hasło
  szyfrowane `APP_KEY`), opcjonalny węzeł (bazy aplikacji z tego węzła trafiają
  tu najpierw), limit baz, aktywny,
- `app_databases` — aplikacja, serwer, nazwa bazy `s{id}_{nazwa}`, użytkownik
  `u{id}_{losowe}`, hasło (szyfrowane), dozwolony host połączeń (`%` albo IP),
- `app_plans.databases` i `app_servers.database_limit` — limit baz.

**Klient — zakładka „Bazy danych”**

- utworzenie bazy (nazwa, dozwolony host), dane połączenia (host, port, baza,
  użytkownik, hasło do odkrycia/skopiowania, gotowy adres JDBC),
- nowe hasło, usunięcie bazy (z potwierdzeniem),
- **Zarządzaj** — przeglądarka bazy w panelu, połączenie kontem klienta (nie
  administracyjnym), więc uprawnienia ogranicza sam serwer bazy:
  - tabele z liczbą wierszy i rozmiarem, struktura tabeli, przeglądanie
    wierszy ze stronicowaniem,
  - konsola SQL (wyniki SELECT do 500 wierszy, liczba zmienionych wierszy dla
    pozostałych, limit czasu zapytania),
  - eksport bazy do pliku `.sql`, import pliku `.sql`.

**Administracja → Aplikacje → Bazy danych**: serwery baz (dodaj, edytuj, test
połączenia, wersja serwera, liczba baz), lista wszystkich baz klientów.

Usunięcie aplikacji usuwa jej bazy z serwerów. Błąd serwera bazy nie blokuje
usunięcia aplikacji (trafia do dziennika).

**Testy**: logika na atrapie serwera baz; dodatkowo test integracyjny na
prawdziwym MariaDB (w CI jako usługa), pomijany, gdy baza nie jest dostępna.

## Instalacja MariaDB na węźle jednym kliknięciem

**Administracja → Aplikacje → Bazy danych → „Zainstaluj MariaDB na węźle”.**

1. Panel zleca instalację agentowi (`POST /system/mariadb`). Agent nie ma roota —
   zostawia plik-zlecenie, a jednostka `virthub-mariadb.path` uruchamia jako root
   `scripts/setup-mariadb.sh` (tak samo jak zdalna aktualizacja węzła).
2. Skrypt instaluje `mariadb-server`, ustawia nasłuchiwanie na wszystkich adresach
   i zakłada konto `virthub_panel` z losowym hasłem (40 znaków) **dostępne tylko z
   adresu panelu**. Adres pochodzi z nagłówka `X-Real-IP`, który ustawia nginx węzła;
   bez niego instalacja jest odrzucana — konto nigdy nie dostaje hosta `%`.
3. Port w zaporze (ufw) jest otwierany tylko po zaznaczeniu tej opcji przy zleceniu.
4. Panel odpytuje stan (strona odświeża się co 10 s, harmonogram co minutę), po
   zakończeniu sam dodaje serwer baz, sprawdza połączenie i każe węzłowi usunąć
   plik z hasłem. Gdy połączenie się nie uda (np. zapora), serwer zostaje zapisany
   jako wyłączony z komunikatem, co poprawić.
