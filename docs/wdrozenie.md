# Wdrożenie produkcyjne

## Szybka instalacja — jedno polecenie

Cały system instaluje się dwoma poleceniami: jednym na serwerze panelu, jednym
na każdym hypervisorze. Nie trzeba edytować żadnego pliku — hasła są generowane
i wypisywane na końcu.

### 1. Panel

Na świeżym serwerze z Debianem 12/13 albo Ubuntu 22.04/24.04:

```bash
curl -sSL https://raw.githubusercontent.com/TeYroXOfficial/virthub/main/infra/install-panel.sh | sudo bash -s -- --domain panel.twojadomena.pl --repo https://github.com/TeYroXOfficial/virthub.git
```

Instalator sam:

- dobiera wersję PHP (dokłada repozytorium, jeśli system ma za starą)
- instaluje MariaDB, Redis, nginx, supervisor, cron i wszystkie rozszerzenia PHP
- wyłącza Apache, jeśli zajmuje port 80
- zakłada bazę z wygenerowanym hasłem i zapisuje kompletny `.env`
- tworzy konto administratora
- uruchamia kolejkę zadań i harmonogram
- wystawia certyfikat Let's Encrypt, jeśli domena wskazuje na ten serwer

Na końcu wypisuje adres panelu, login i hasło administratora. **Hasło pokazuje
tylko raz.**

Domena musi mieć rekord A wskazujący na serwer, zanim uruchomisz instalator —
inaczej certyfikat się nie wystawi i panel zostanie na HTTP (instalator
powie to wprost i poda polecenie, którym dokończysz TLS później).

Bez `--domain` instalator zapyta o nią; wciśnięcie Enter zostawia panel na
adresie IP, bez szyfrowania.

#### Skąd instalator bierze kod

Polecenie `curl` wymaga, żeby skrypt był dostępny pod publicznym adresem.
Najprościej wrzucić repozytorium na GitHub — wtedy działa polecenie z góry.
Dla repozytorium prywatnego `raw.githubusercontent.com` wymaga tokenu, więc
użyj drugiego wariantu:

```bash
sudo bash install-panel.sh --domain panel.twojadomena.pl --source /root/virthub
```

gdzie `/root/virthub` to wgrany na serwer katalog repozytorium (cały, nie tylko
`control-plane`). `install-panel.sh` leży w `infra/`.

#### Aktualizacja

Uruchom to samo polecenie jeszcze raz. Instalator wykrywa istniejącą instalację
i zachowuje klucz aplikacji, bazę, hasła i konto administratora — podmienia
tylko kod, zależności i konfigurację usług.

### Aktualizacje z panelu

**Administracja → Aktualizacje** pokazuje wersję panelu i agenta na każdym
węźle względem najnowszego commita w repozytorium (`VIRTHUB_UPDATE_REPO`,
`VIRTHUB_UPDATE_BRANCH`) i pozwala zlecić aktualizację panelu, jednego węzła
albo wszystkich nieaktualnych.

Ani panel (`www-data`), ani agent (`virthub`) nie mają uprawnień roota.
Zlecenie to plik (`/var/lib/virthub-panel/request`, `/var/lib/virthub/update/request`),
a aktualizację wykonuje jako root jednostka systemd `*.path` → `*.service`:

| Serwer | Usługa | Skrypt | Źródło kodu |
|---|---|---|---|
| panel | `virthub-panel-update` | `infra/update-panel.sh` | `/etc/virthub/panel.conf` |
| węzeł | `virthub-agent-update` | `node-agent/scripts/update-node.sh` | `/etc/virthub-agent/update.env` |

Panel może aktualizację tylko wyzwolić — skąd pobrać kod, ustala plik na
serwerze, więc przejęty panel nie wskaże węzłowi obcego kodu. Log ostatniej
aktualizacji: `/var/lib/virthub-panel/last.log` i `/var/lib/virthub/update/last.log`.

Aktualizacja pomija kroki, których wynik się nie zmienił: pakiety systemowe
(gdy wszystkie są zainstalowane), `composer install` (gdy `composer.lock` i
wersja PHP są te same — odświeża tylko autoloader), zależności przekaźnika
konsoli i agenta (gdy `requirements.txt` jest ten sam) oraz wystawianie
certyfikatu (istniejący jest tylko podpinany do nginx; odnawia go certbot).
Wszystko od nowa: `install-panel.sh --full` albo `VH_FULL_INSTALL=1`.

Instalacje sprzed tej funkcji trzeba raz zaktualizować ręcznie — to zakłada
usługi aktualizacji:

```bash
# panel
curl -sSL https://raw.githubusercontent.com/TeYroXOfficial/virthub/main/infra/install-panel.sh | sudo bash -s -- --domain panel.twojadomena.pl --repo https://github.com/TeYroXOfficial/virthub.git
# każdy węzeł
curl -sSL https://raw.githubusercontent.com/TeYroXOfficial/virthub/main/infra/update-node.sh | sudo bash
```

Aktualizacja węzła podmienia kod agenta, jednostkę systemd i konfigurację
nginx; nie rusza rejestracji, sekretów, certyfikatu, mostka ani maszyn —
działające maszyny nie są restartowane.

### Konta i uprawnienia

**Administracja → Użytkownicy**: zakładanie kont (hasło nadane albo
wygenerowane i pokazane raz), rola, uprawnienia, limity i blokada konta.

- Rola wyznacza domyślny zestaw uprawnień: klient — wszystko przy własnych
  maszynach; wsparcie — dodatkowo maszyny klientów i konta klientów;
  administrator — wszystko (nie da się go ograniczyć).
- „Własne uprawnienia" zawężają albo rozszerzają zestaw per konto: zamawianie,
  zasilanie, konsola, zapora, snapshoty, zmiana pakietu, reinstalacja,
  usuwanie; dla personelu — dostęp do poszczególnych działów administracji.
- Limit maszyn (puste = `VIRTHUB_SERVERS_PER_CUSTOMER`) i lista dozwolonych
  pakietów działają w panelu i w API.
- Zablokowane konto jest wylogowywane przy następnym żądaniu, a jego tokeny
  API unieważniane; maszyny działają dalej.
- Wsparcie zarządza tylko kontami klientów. Nie da się zablokować, usunąć ani
  zdegradować samego siebie ani ostatniego aktywnego administratora.

### Zapora maszyn

Każda maszyna ma zaporę zarządzaną ze strony maszyny w panelu (klient i
personel) albo przez API (`/api/v1/servers/{id}/firewall`):

- włączenie/wyłączenie i domyślna polityka ruchu przychodzącego i
  wychodzącego (przepuszczaj albo blokuj poza regułami);
- reguły: zezwól/zablokuj, kierunek, TCP/UDP/ICMP/wszystko, port lub zakres,
  adres IP albo podsieć (IPv4/IPv6); kolejność, włączanie pojedynczych reguł,
  szablony (SSH, HTTP/HTTPS, ping, RDP, poczta);
- personel może dodać **reguły administratora** (sprawdzane przed regułami
  klienta, dla klienta tylko do odczytu) i **zablokować** klientowi zmiany.

Przy blokowaniu odpowiedzi na połączenia nawiązane przez maszynę przechodzą
dzięki śledzeniu połączeń w rodzinie bridge (moduł `nf_conntrack_bridge`,
ładowany przez instalator i aktualizację węzła). Bez modułu agent przechodzi
w tryb bezstanowy (przepuszcza TCP bez samego SYN i odpowiedzi DNS/NTP) —
heartbeat węzła zgłasza to polem `firewall_stateful`.

### Konsola w przeglądarce

Maszyna KVM dostaje ekran przez noVNC, kontener LXC — terminal xterm.js
(logowanie jako `root` hasłem z panelu). Przeglądarka nie łączy się z węzłem:

```
przeglądarka ─wss─▶ nginx panelu (/console-ws/) ─▶ virthub-console (127.0.0.1:8090)
             ─wss, przypięty certyfikat, podpis HMAC─▶ nginx węzła ─▶ agent ─▶ VNC / terminal
```

Instalator panelu stawia usługę `virthub-console` (katalog `console-proxy/`),
generuje `VIRTHUB_CONSOLE_SECRET` i dodaje wewnętrzny serwer nginx na
`127.0.0.1:8091`, przez który przekaźnik wymienia jednorazowe sesje. Diagnoza:
`journalctl -u virthub-console -n 50`.

### 2. Hypervisor

Nie potrzebujesz żadnego skryptu z repozytorium. W panelu wejdź w
**Administracja → Hypervisory**, podaj nazwę węzła i skopiuj polecenie, które
się pojawi. Wklej je na serwerze z KVM jako root.

Instalator węzła sam:

- sprawdza, czy procesor wspiera wirtualizację
- instaluje KVM, libvirt i agenta
- **konfiguruje mostek sieciowy** — z automatycznym wycofaniem: jeśli po zmianie
  sieci serwer straci łączność, w ciągu 3 minut wraca do poprzednich ustawień
- generuje certyfikat TLS, który panel przypina do tego węzła
- zgłasza się do panelu z wykrytymi zasobami (rdzenie, RAM, dysk)
- pobiera obraz Ubuntu 24.04, żeby od razu dało się utworzyć maszynę

#### Węzeł bez sprzętowej wirtualizacji — kontenery LXC

Jeśli procesor nie zgłasza VT-x/AMD-V (typowo VPS bez zagnieżdżonej
wirtualizacji), instalator nie odmawia — stawia **Incusa** i węzeł uruchamia
kontenery LXC zamiast maszyn wirtualnych. Tworzy też pulę dyskową btrfs, na
której działa limit dysku z pakietu.

Szablony kontenerów nie wymagają wgrywania: w **Administracja → Szablony**
dodajesz system z katalogu jednym kliknięciem, a panel sam zleca pobranie na
wszystkie węzły kontenerów (obrazy z `images.linuxcontainers.org`, tego samego
źródła, z którego korzysta Proxmox). Stan pobrania widać przy szablonie, osobno
dla każdego węzła. Węzeł, który dołączy później, dociągnie szablony sam przy
pierwszym kontakcie z panelem.

Klient przy zamawianiu widzi, czy wybiera maszynę wirtualną, czy kontener, i
widzi tylko te systemy, dla których jest działający węzeł danego typu.

Żeby wymusić kontenery na serwerze, który obsługuje KVM:

```bash
curl -sSL https://panel.twojadomena.pl/enroll/TOKEN | sudo VH_VIRT=lxc bash
```

**Sieć kontenerów na VPS-ie.** Kontenery, tak jak maszyny KVM, dostają
publiczne adresy z puli i wychodzą w sieć przez mostek z własnym adresem MAC.
Na VPS-ie z jednym adresem IP to nie zadziała — dostawca musi przydzielić
dodatkowe adresy i dopuścić ruch z dodatkowych MAC-ów (albo dodatkowe IP muszą
być routowane na serwer). Jeśli to niemożliwe, użyj puli **NAT** (niżej) —
maszyny dostają adresy prywatne i wychodzą w świat adresem węzła.

Po około minucie węzeł pojawia się w panelu jako online. Zostaje zaimportować
pulę adresów IP (**Administracja → Adresy IP**) — tego nie da się wykryć
automatycznie, bo to zależy od tego, co przydzielił ci dostawca.

#### Kiedy mostek trzeba zrobić ręcznie

Automat konfiguruje wyłącznie prosty, najczęstszy układ: jeden interfejs z
adresem i bramą. Jeśli serwer używa VLAN-ów, bondingu albo ma już jakiś mostek,
instalator nie rusza sieci i mówi o tym wprost — w takim układzie automat
zrobiłby więcej szkody niż pożytku. Wyłączenie automatu:

```bash
curl -sSL https://panel.twojadomena.pl/enroll/TOKEN | sudo VH_SETUP_BRIDGE=0 bash
```

---

## Instalacja ręczna

Poniżej to samo krok po kroku — na wypadek nietypowego systemu albo gdy chcesz
wiedzieć, co dokładnie robi instalator.

## Co gdzie trafia

To **dwa osobne serwery o różnych wymaganiach**. Wrzucenie wszystkiego w jedno
miejsce działa tylko w developmencie.

| Komponent | Maszyna | Wymagania |
|---|---|---|
| `control-plane/` | Zwykły serwer WWW (może być VPS) | PHP 8.2+, nginx, MySQL, Redis. **Nie potrzebuje KVM** |
| `node-agent/` | Hypervisor — bare metal z KVM | Procesor z VT-x/AMD-V, libvirt, mostek sieciowy |

Panel może stać na tanim VPS-ie u dowolnego dostawcy. Hypervisor musi być
maszyną fizyczną (albo VPS-em z zagnieżdżoną wirtualizacją, ale wtedy wydajność
maszyn klientów będzie zauważalnie gorsza).

Można oba postawić na jednym serwerze fizycznym, ale wtedy awaria hypervisora
zabiera też panel — czyli nie ma z czego zdiagnozować awarii ani powiadomić klientów.

---

## Czego NIE kopiujesz na serwer

Nie przenoś katalogu z dysku ręcznie. Te rzeczy nie mogą trafić na produkcję:

| Nie kopiuj | Dlaczego |
|---|---|
| `vendor/` | Instalujesz na serwerze — inna wersja PHP potrzebuje innych zależności |
| `.env` | Zawiera lokalne sekrety; produkcja ma własny plik |
| `database/database.sqlite` | Baza developerska z kontami o jawnych hasłach |
| `node-agent/.venv` | Wirtualne środowisko jest przypisane do ścieżki i systemu |
| `storage/logs/*`, `bootstrap/cache/*` | Śmieci z developmentu |

`.gitignore` już to wszystko wyklucza, więc najprościej wdrażać przez `git clone`.

---

## Control plane — krok po kroku

### 1. Pakiety systemowe (Ubuntu 24.04)

```bash
sudo apt update
sudo apt install -y nginx mysql-server redis-server supervisor git unzip \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring \
    php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath
```

### 2. Kod i zależności

```bash
sudo mkdir -p /var/www/virthub && sudo chown $USER:$USER /var/www/virthub
git clone <adres-repozytorium> /var/www/virthub
cd /var/www/virthub/control-plane

curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer install --no-dev --optimize-autoloader
```

`--no-dev` jest istotne: bez niego na produkcję trafia PHPUnit i narzędzia
developerskie, które nie mają tam czego szukać.

### 3. Baza danych

```bash
sudo mysql -e "CREATE DATABASE virthub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'virthub'@'localhost' IDENTIFIED BY 'TU_MOCNE_HASLO';"
sudo mysql -e "GRANT ALL PRIVILEGES ON virthub.* TO 'virthub'@'localhost'; FLUSH PRIVILEGES;"
```

### 4. Konfiguracja

```bash
cp .env.example .env
php artisan key:generate
nano .env
```

Ustaw co najmniej:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://panel.twojadomena.pl

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=virthub
DB_USERNAME=virthub
DB_PASSWORD=TU_MOCNE_HASLO

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

VIRTHUB_BRAND=NazwaTwojejFirmy
VIRTHUB_LOCALE=pl
```

`VIRTHUB_LOCALE` to język domyślny panelu (`pl` albo `en`). Każdy użytkownik
może go zmienić przełącznikiem PL/EN w menu bocznym albo na stronie logowania —
wybór jest zapamiętywany na jego koncie. Bez wyboru panel dopasowuje się do
języka przeglądarki.

`APP_DEBUG=false` jest obowiązkowe — przy `true` strona błędu pokazuje
zawartość `.env`, łącznie z hasłem do bazy i kluczem aplikacji.

### 5. Migracje i konto administratora

```bash
php artisan migrate --force
```

**Nie uruchamiaj `--seed` na produkcji** — seeder zakłada konta o jawnych,
publicznie znanych hasłach. Administratora załóż ręcznie:

```bash
php artisan tinker
```

```php
App\Models\User::create([
    'name' => 'Administrator',
    'email' => 'twoj@email.pl',
    'password' => 'wlasne-mocne-haslo',
    'role' => 'admin',
    'email_verified_at' => now(),
]);
```

### 6. Uprawnienia i cache

```bash
sudo chown -R www-data:www-data /var/www/virthub/control-plane/storage \
                                /var/www/virthub/control-plane/bootstrap/cache

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Cache konfiguracji trzeba **przebudować po każdej zmianie `.env`** — inaczej
aplikacja dalej czyta stare wartości.

### 7. nginx

Kluczowe: katalogiem głównym jest `public/`, nie katalog projektu. Wskazanie
`/var/www/virthub/control-plane` udostępniłoby `.env` przez przeglądarkę.

```nginx
server {
    listen 80;
    server_name panel.twojadomena.pl;
    root /var/www/virthub/control-plane/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/virthub /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d panel.twojadomena.pl
```

HTTPS nie jest opcjonalny: przez panel przechodzą hasła root maszyn i tokeny
integracji rozliczeniowej.

### 8. Kolejka zadań — bez tego nic nie zadziała

**To jest najczęściej pomijany krok.** Bez działającego workera zamówienie
maszyny trafia do kolejki i zostaje tam na zawsze — panel pokazuje „Tworzenie"
i nigdy nic się nie dzieje.

`/etc/supervisor/conf.d/virthub-worker.conf`:

```ini
[program:virthub-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/virthub/control-plane/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/virthub-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status virthub-worker:*
```

### 9. Harmonogram — heartbeat i uzgadnianie stanu

Też obowiązkowy. Bez niego panel nie wie, czy hypervisor żyje, i nie uzgadnia
wyników zgubionych zadań.

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/virthub/control-plane && php artisan schedule:run >> /dev/null 2>&1
```

---

## Node agent — na hypervisorze

Nie kopiuj plików ręcznie, użyj playbooka:

```bash
# na maszynie, z której zarządzasz (nie na hypervisorze)
cd /sciezka/do/virthub

cat > inventory.ini <<'EOF'
[hypervisors]
node1 ansible_host=1.2.3.4 ansible_user=root
EOF

ansible-playbook -i inventory.ini infra/ansible/deploy-agent.yml \
    -e "agent_token=... callback_secret=... control_plane_url=https://panel.twojadomena.pl"
```

Wartości `agent_token` i `callback_secret` bierzesz z odpowiedzi panelu przy
rejestracji hypervisora (patrz niżej) — playbook ich nie wymyśla.

### Czego playbook celowo nie robi

**Nie konfiguruje mostka sieciowego.** Nazwa interfejsu, VLAN-y i adresacja
różnią się u każdego dostawcy, a błąd w tym miejscu odcina serwer od sieci —
z konsoli KVM dostawcy da się to odkręcić, zdalnie już nie. Skonfiguruj `br0`
ręcznie zgodnie z dokumentacją dostawcy i sprawdź, że host dalej odpowiada,
zanim uruchomisz agenta.

### Obrazy szablonów

Agent nie pobiera ich sam:

```bash
cd /var/lib/virthub/templates
curl -LO https://cloud-images.ubuntu.com/releases/24.04/release/ubuntu-24.04-server-cloudimg-amd64.img
mv ubuntu-24.04-server-cloudimg-amd64.img ubuntu-24.04.qcow2
chown virthub:libvirt ubuntu-24.04.qcow2
```

Obrazu bazowego nie wolno usunąć ani nadpisać, dopóki istnieje choć jedna
maszyna, która go używa — dyski klientów są cienkimi warstwami nad nim.

### Transfer

Panel liczy transfer każdej maszyny w miesiącu kalendarzowym z liczników
karty sieciowej (co minutę, przy zbieraniu metryk). Jednostki dziesiętne:
1 GB = 1000³ B. Do limitu z pakietu (`bandwidth_gb`, 0 = bez limitu) liczy
się ruch w obie strony — `VIRTHUB_TRAFFIC_COUNTING=out` (albo `in`) w `.env`
panelu zmienia to na jeden kierunek. Po przekroczeniu maszyna zostaje
zawieszona; 1. dnia miesiąca, po zwiększeniu limitu albo wyzerowaniu
licznika (strona maszyny → Ustawienia, personel) odblokowuje się sama.
Zawieszenie za płatność nie jest przy tym zdejmowane.

### Izolacja maszyn od węzła

- **Kontenery** są nieuprzywilejowane (`security.privileged=false`), bez
  zagnieżdżania, z limitem procesów (`VH_CT_MAX_PROCESSES`, domyślnie 4096)
  i — gdy root ma przydział w `/etc/subuid`/`/etc/subgid` (ustawia go
  instalator i aktualizacja węzła) — z osobnym zakresem UID/GID na kontener.
- Agent **nie uruchomi** kontenera, jeśli profil `default` Incusa dopisuje
  coś, co osłabia izolację (`raw.lxc`, `security.privileged`, katalog hosta,
  urządzenia GPU/USB, `proxy`…). Stan widać na stronie węzła → Informacje.
- **Maszyny nie połączą się z usługami węzła** (SSH hosta, agent, cokolwiek
  na bramie NAT): tabela `inet virthub_guard` przepuszcza tylko ping/ICMP,
  ND IPv6 i odpowiedzi na połączenia węzła. Ruch przez węzeł (NAT, routing)
  działa normalnie. Wyłączenie: `VH_GUARD_HOST=0` w konfiguracji agenta.
- Po **restarcie węzła** agent sam odtwarza zaporę, anty-spoofing i ochronę
  węzła z zapisanego stanu (`/var/lib/virthub/network`).

### Systemy i wersje

Szablony są zgrupowane w systemy (**Administracja → Szablony**): np. „Ubuntu"
z wersjami 22.04 i 24.04, osobno dla KVM i LXC. Klient wybiera najpierw
system, potem wersję — przy zamówieniu i przy reinstalacji. Po aktualizacji
istniejące szablony trafiają do systemów automatycznie, według rodziny.
Ukrycie systemu wyłącza wszystkie jego wersje naraz; wersję bez maszyn można
usunąć.

### Węzły, grupy i sprzątanie maszyn

Każdy węzeł ma własną stronę (**Hypervisory → Zarządzaj**): zajętość, maszyny
na węźle, ustawienia (nazwa, grupa, limit maszyn, notatki, pojemność, tryb
pracy) i usuwanie. Grupa węzłów może mieć lokalizację widoczną dla klientów —
wtedy klient wybiera ją przy zamówieniu — oraz wstrzymane przyjmowanie maszyn
dla wszystkich węzłów grupy naraz.

Maszyny usuwa się z listy **Maszyny** (pojedynczo albo zaznaczone). „Usuń"
kasuje maszynę na węźle. „Z panelu" (tylko administrator) usuwa sam wpis bez
kontaktu z węzłem — dla maszyn, których węzeł już nie istnieje, które zniknęły
z węzła albo których operacja utknęła; adresy IP wracają do pul. Martwy węzeł
można usunąć razem z wpisami jego maszyn po wpisaniu jego nazwy.

### Obrazy ISO (KVM)

Płyty instalacyjne dodaje się w panelu: **Administracja → Obrazy ISO** —
nazwa, URL i (zalecane) SHA-256. Każdy zarejestrowany węzeł KVM pobiera plik
sam do `/var/lib/virthub/isos`; węzły LXC są pomijane. Stan pobrania widać
per węzeł, nieudane można ponowić. Klient montuje płytę na stronie maszyny
(uprawnienie „Obrazy ISO"), zaznacza rozruch z płyty i instaluje system przez
konsolę noVNC. Obrazu zamontowanego w jakiejkolwiek maszynie nie da się usunąć.

Reinstalacja: przycisk „Reinstaluj" obok zasilania otwiera wybór systemu.
Czyści dysk i zachowuje adresy IP, zaporę i parametry; w trakcie strona
pokazuje postęp i odświeża się sama. Support nie może reinstalować cudzych
maszyn — tylko administrator.

Reset hasła roota (zakładka „Ustawienia" maszyny) działa w uruchomionej
maszynie. KVM potrzebuje w gościu `qemu-guest-agent` — maszyny tworzone od
tej wersji mają go od startu; w starszych trzeba go doinstalować
(`apt install qemu-guest-agent`) albo zrobić reinstalację.

---

### Aplikacje: serwery gier i boty (jak Pterodactyl)

Oprócz VPS-ów węzeł może uruchamiać **aplikacje**: serwery gier, boty Discord
i inne usługi w kontenerach Dockera. Agent działa wtedy jak Wings: instaluje
aplikację skryptem z szablonu, uruchamia ją z limitami, wystawia porty,
przekazuje konsolę na żywo (WebSocket), pliki i SFTP.

Szablony to **eggi w formacie Pterodactyla** (PTDL_v1 i PTDL_v2), więc działają
gotowe eggi społeczności (np. pelican-eggs, parkervcp/eggs) z tymi samymi
obrazami `ghcr.io/pterodactyl/yolks` / `ghcr.io/parkervcp/yolks` i skryptami
instalacyjnymi. Panel ma wbudowane: Minecraft Paper, Minecraft Vanilla,
bot Discord Node.js i bot Discord Python.

**Włączenie na węźle:**

1. Zainstaluj Dockera — przy instalacji albo aktualizacji węzła dodaj `VH_APPS=1`:

   ```bash
   curl -sSL https://raw.githubusercontent.com/TeYroXOfficial/virthub/main/infra/update-node.sh | sudo VH_APPS=1 bash
   ```

   Skrypt instaluje `docker.io`, dodaje użytkownika agenta do grupy `docker`
   i tworzy `/var/lib/virthub/apps`. Po restarcie agent zgłasza Dockera
   w heartbeacie (widać to w Ustawieniach węzła).
2. W panelu: Hypervisory → węzeł → Ustawienia → „Uruchamiaj aplikacje na tym
   węźle” i zakres portów (np. 25565–25665). Porty bloków NAT maszyn są
   pomijane automatycznie. Otwórz ten zakres (TCP i UDP) także w firewallu
   dostawcy.
3. Administracja → Aplikacje → Szablony: „Wgraj wbudowane szablony” albo
   zaimportuj egg z pliku JSON. → Plany: dodaj plany (RAM, procesor w % rdzenia,
   dysk, liczba portów).

Klient zamawia aplikację w zakładce **Aplikacje** (uprawnienie „Zamawianie
aplikacji”). Strona aplikacji ma konsolę na żywo z zasilaniem i statystykami, menedżer
plików (edycja, wgrywanie do 50 MB, katalogi, rozpakowywanie zip/tar),
zmienne i wersję środowiska (obraz Dockera) oraz reinstalację i usunięcie.
Personel zmienia zasoby, zawiesza i odwiesza aplikacje.

Jak to działa na węźle:

- instalacja — skrypt eggu w kontenerze instalatora (jako root), katalog
  aplikacji pod `/mnt/server`; na koniec pliki przechodzą na użytkownika agenta;
- uruchomienie — kontener `vh-app-<uuid>` jako użytkownik agenta (jak Wings),
  katalog pod `/home/container`, limity pamięci, procesora (`nano_cpus`)
  i procesów, bez zbędnych uprawnień (`cap_drop`, `no-new-privileges`),
  w sieci `virthub_apps` bez komunikacji między kontenerami;
- porty — publikowane na wszystkich adresach węzła (TCP i UDP); pierwszy port
  to `SERVER_PORT`, a pliki konfiguracyjne z eggu (np. `server-port`
  w `server.properties`) agent ustawia przed każdym startem;
- dysk — przekroczony limit blokuje start (jak w Wings);
- DNS — kontenery dostają `1.1.1.1` i `1.0.0.1` (jak w Wings), bo resolvery
  hosta bywają z kontenera nieosiągalne (stub systemd-resolved `127.0.0.53`,
  same adresy IPv6). Inne serwery ustawisz w `VH_APPS_DNS` w
  `/etc/virthub-agent/agent.env` (lista po przecinku, puste = DNS z hosta);
  test: `docker run --rm --network virthub_apps alpine nslookup github.com`;
- pliki — agent obsługuje je przez deskryptory z `O_NOFOLLOW`, więc
  dowiązania symboliczne i `..` nie wyprowadzą poza katalog aplikacji.

**Konsola na żywo.** Wyjście aplikacji płynie przez WebSocket tym samym
przekaźnikiem co konsola VPS (`virthub-console`): przeglądarka → nginx panelu
→ przekaźnik → agent (`/apps/<uuid>/console`, podpis HMAC). Widać log
instalacji w trakcie, start, restart i polecenia bez odświeżania, a stan, CPU,
RAM i dysk odświeżają się co 2 s. Bez skonfigurowanego przekaźnika
(`VIRTHUB_CONSOLE_SECRET`) konsola sama przechodzi na odpytywanie co 1,5 s.

**SFTP.** Agent wystawia serwer SFTP (domyślnie port **2022/tcp** na wszystkich
adresach węzła) — otwórz go w firewallu węzła i dostawcy. Klient loguje się:

- host: publiczny adres węzła, port 2022,
- użytkownik: `u<id użytkownika>.<8 znaków UUID aplikacji>` (widać w
  Ustawieniach aplikacji, np. `u2.72c35e5c`),
- hasło: hasło do panelu.

Agent nie zna haseł — pyta panel (`POST /api/internal/agent/sftp-auth`, podpis
sekretem węzła), czy ten użytkownik może zarządzać aplikacją; nieudane próby są
limitowane. Po zalogowaniu `/` to katalog aplikacji: te same zabezpieczenia co w
menedżerze plików (`O_NOFOLLOW`, bez `..`, bez tworzenia dowiązań), zapis
blokuje przekroczony limit dysku, powłoki i przekierowań portów nie ma. Klucz
hosta (`/var/lib/virthub/sftp_host_ed25519`) powstaje przy pierwszym starcie.
Port zmienisz w `VH_SFTP_PORT` w `/etc/virthub-agent/agent.env` (`0` wyłącza
SFTP, `VH_SFTP_LISTEN` to adres nasłuchu) — wtedy ustaw ten sam port w `.env`
panelu: `VIRTHUB_APPS_SFTP_PORT`.

**Modpacki, pluginy i mody jednym kliknięciem.** Aplikacje Minecraft mają
zakładki *Modpacki* i *Pluginy* (Paper) albo *Mody* (Forge, NeoForge, Fabric,
Quilt):

- modpacki z **Modrinth** (.mrpack), **Feed The Beast** i **CurseForge** —
  panel sam dobiera loader, jego wersję i Javę (1.16 → 8, 1.17–1.20.4 → 17,
  1.20.5+ → 21, 26.x → 25), przełącza aplikację na egg „Minecraft: modpack /
  mody” i instaluje paczkę; świat, `server.properties` i listy graczy zostają
  (opcja „Usuń też świat” daje świeży start). Pliki tylko dla klienta są
  pomijane (Modrinth `env.server`, FTB `clientonly`); z CurseForge panel
  bierze server pack autora, jeśli jest;
- czysty serwer: vanilla, Fabric, Quilt, Forge albo NeoForge w wybranej wersji
  (instalatory Forge/NeoForge/Quilt działają w kontenerze z obrazem aplikacji);
- pluginy z **Modrinth**, **Hangar** i **CurseForge**, mody z **Modrinth**
  i **CurseForge** — lista pokazuje tylko wersje zgodne z serwerem (wersja
  gry + platforma), wymagane zależności instalują się same, jest
  sprawdzanie aktualizacji i usuwanie.

Każdy plik agent pobiera tylko przez https, z weryfikacją SHA-1/SHA-256/
SHA-512 i rozmiaru, bez adresów prywatnych (także po przekierowaniach), w
limicie dysku aplikacji. Postęp instalacji modpacka i loadera widać na żywo w
konsoli. Panel nie pozwala zejść na starszą wersję gry bez usunięcia świata
(Minecraft nie wczyta nowszego świata).

CurseForge wymaga darmowego klucza API: załóż go w
[console.curseforge.com](https://console.curseforge.com) i wpisz w `.env`
panelu jako `VIRTHUB_CURSEFORGE_API_KEY=` — bez klucza to źródło jest ukryte,
a Modrinth, FTB i Hangar działają od razu. Część autorów na CurseForge blokuje
pobieranie przez zewnętrzne aplikacje — panel mówi wtedy, których modów to
dotyczy (wybierz wersję z server packiem albo wgraj je przez SFTP).

**Ochrona przed nadużyciami (PteroVM i podobne).** Aplikacje to serwery gier
i boty, nie VPS-y. „Eggi VPS” (PteroVM, Pterodactyl-VPS-Egg itp.) uruchamiają
w kontenerze cały system przez proot albo QEMU, żeby dać klientowi roota,
kopać kryptowaluty albo wystawić SSH. Obrona ma trzy warstwy:

1. *Zapobieganie* — kontener aplikacji działa jako zwykły użytkownik, bez
   żadnych uprawnień jądra (`cap_drop: ALL`), z `no-new-privileges` i własnym
   profilem seccomp: domyślny profil Dockera bez `ptrace`,
   `process_vm_readv/writev`. Na `ptrace` stoi proot — bez niego PteroVM nie
   wystartuje. Instalator eggu dostaje ten sam profil. Po aktualizacji
   węzła każda aplikacja przy najbliższym starcie dostaje nowy kontener.
2. *Wykrywanie* — agent co minutę sprawdza procesy każdej działającej
   aplikacji (`docker top`, z hosta, więc nie da się ich ukryć), a co 10 minut
   i przed każdym startem jej pliki. Szuka proot/udocker/QEMU, rozpakowanego
   systemu Linux (`etc/os-release` + `bin` + `usr`), koparek (xmrig i inne,
   `stratum+tcp://` w poleceniu), serwerów SSH i zdalnych powłok (sshd,
   tmate, ttyd…). Takie znalezisko zabija kontener albo blokuje start;
   aplikacja jest zawieszana w panelu z powodem widocznym dla klienta i
   personelu (Administracja → Aplikacje, znacznik „nadużycie”). Tunele
   (ngrok, cloudflared, frpc…) są tylko zgłaszane.
3. *Import eggów* — panel odrzuca eggi, które instalują proot, QEMU, koparki
   albo serwer SSH.

Przy fałszywym alarmie administrator w Ustawieniach aplikacji wybiera
„Fałszywy alarm — wyłącz ochronę” i odwiesza aplikację. Ustawienia węzła
(`/etc/virthub-agent/agent.env`): `VH_APPS_GUARD=kill` (domyślnie; `report` —
tylko zgłasza, `off` — wyłącza), `VH_APPS_GUARD_INTERVAL=60`,
`VH_APPS_SECCOMP=1`. Panel: `VIRTHUB_APPS_ABUSE_SUSPEND=true` (`false` —
zgłoszenie bez zawieszania).

Uwaga: Docker ładuje moduł `br_netfilter` i ustawia politykę `FORWARD` na
`DROP`. Agent przy starcie (`scripts/nat-forward.sh`) przepuszcza ruch mostków
maszyn, więc VPS-y na tym samym węźle nie tracą sieci.

## Połączenie obu części

### 1. Zarejestruj hypervisor w panelu

Zaloguj się jako administrator, wygeneruj token API i wywołaj:

```bash
curl -X POST https://panel.twojadomena.pl/api/v1/admin/hypervisors \
  -H "Authorization: Bearer TWOJ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "node1",
    "hostname": "node1.twojadomena.pl",
    "agent_url": "https://node1.twojadomena.pl:8899",
    "cpu_cores_total": 32,
    "ram_mb_total": 131072,
    "disk_gb_total": 3500
  }'
```

Odpowiedź zawiera `agent_env` z tokenami — **pokazywane dokładnie raz**. To
one idą do playbooka Ansible. Zgubione trzeba wygenerować od nowa
(`POST /admin/hypervisors/{id}/rotate-token`).

Pojemność podajesz **mniejszą niż fizyczna** — zostaw zapas na system hosta
(zwykle 2–4 rdzenie i 8–16 GB RAM), inaczej hypervisor zacznie się dławić przy
pełnym obłożeniu.

### 2. Wystaw agenta przez TLS

Agent nasłuchuje wyłącznie na `127.0.0.1:8899`. Ruch z panelu wpuszczasz przez
nginx na hypervisorze:

```nginx
server {
    listen 8899 ssl;
    server_name node1.twojadomena.pl;

    ssl_certificate     /etc/letsencrypt/live/node1.twojadomena.pl/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/node1.twojadomena.pl/privkey.pem;

    # Do agenta puszczamy tylko panel. Podpis HMAC i tak by obronił, ale nie ma
    # powodu wystawiać tego API na cały internet.
    allow IP_SERWERA_PANELU;
    deny all;

    location / {
        proxy_pass http://127.0.0.1:8899;
        proxy_set_header Host $host;
    }
}
```

### 3. Zsynchronizuj zegary

Podpis żądań zawiera znacznik czasu z tolerancją 300 sekund. Rozjechane zegary
oznaczają, że panel nie dogada się z agentem, a błąd wygląda jak zły token.

```bash
sudo timedatectl set-ntp true    # na obu maszynach
```

### 4. Zaimportuj pulę adresów

```bash
curl -X POST https://panel.twojadomena.pl/api/v1/admin/ip-pools \
  -H "Authorization: Bearer TWOJ_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "hypervisor_id": 1,
    "name": "Pula podstawowa",
    "cidr": "1.2.3.0/24",
    "gateway": "1.2.3.1",
    "prefix": 24,
    "range_from": "1.2.3.10",
    "range_to": "1.2.3.200"
  }'
```

`range_from`/`range_to` zawężają zakres — część adresów z podsieci należy
zwykle do infrastruktury dostawcy i nie wolno ich przydzielać klientom.

#### Zasięg: węzeł albo grupa węzłów

Pula należy do jednego węzła (`hypervisor_id`) albo do grupy węzłów
(`"scope": "group"`, `hypervisor_group_id`). Pula grupy obsługuje każdy węzeł
grupy, więc dostawca musi dostarczać tę podsieć do wszystkich (wspólny VLAN).
Pule węzła mają pierwszeństwo przed pulami grupy. Grupy zakłada się w
**Administracja → Adresy IP** albo przez `POST /api/v1/admin/hypervisor-groups`.

#### IPv6

Wersję wykrywamy z `cidr`. Pula IPv6 (np. `2001:db8:10::/64`, `prefix: 64`) nie
jest rozwijana na adresy — kolejne adresy powstają przy zamówieniach, od
początku zakresu, z pominięciem bramy. Liczbę adresów IPv6 w pakiecie ustawia
pole `ipv6_count`.

#### NAT

Pula `"type": "nat"` to sieć prywatna (RFC 1918, 100.64.0.0/10 albo ULA
`fc00::/7` dla IPv6) za węzłem. Agent sam zakłada mostek `vhnat0` (zmienna
`VH_NAT_BRIDGE`), nadaje mu adres bramy puli, włącza przekazywanie pakietów i
maskaradę (albo SNAT na `nat_public_address`) w tabeli nftables `inet virthub_nat`.

```json
{
  "hypervisor_id": 1, "type": "nat", "name": "NAT",
  "cidr": "10.10.0.0/24", "gateway": "10.10.0.1", "prefix": 24,
  "nat_port_start": 10000, "nat_ports_per_server": 20
}
```

Każdy adres dostaje stały blok portów wyliczony z pozycji w sieci: `10.10.0.5`
→ porty 10100–10119 (przy 10 portach na maszynę: 10050–10059). Pierwsze porty
bloku są na stałe przypisane do dostępu, zależnie od systemu maszyny (odczytanego
z maszyny, a bez odczytu — z szablonu):

| System  | 1. port bloku     | 2. port bloku     |
|---------|-------------------|-------------------|
| Linux   | SSH / SFTP (22)   | —                 |
| Windows | RDP (3389)        | SSH / SFTP (22)   |

Pozostałe porty klient kieruje w panelu (Przegląd → Przekierowania portów) na
dowolny port w maszynie, np. 10103 → 80. Nieustawione przechodzą 1:1 na ten sam
numer. Po reinstalacji na inny system usługi stałe przeliczają się same.
Bloki pul NAT, które mogą trafić na ten sam węzeł, nie mogą się pokrywać — panel
to sprawdza. Pakiet korzysta z pul NAT, gdy ma `"network_type": "nat"`.

Klienci łączą się z portami pod adresem wyjścia puli (`nat_public_address`),
a bez niego — pod publicznym adresem węzła: wykrytym przez agenta (adres trasy
domyślnej) albo ustawionym ręcznie w Ustawieniach węzła → „Adres publiczny”.
Ustaw go, gdy węzeł stoi za NAT-em dostawcy albo ma kilka adresów.

Uwagi dla węzła:

- ufw i Docker ustawiają politykę łańcucha iptables `FORWARD` na `DROP` —
  wtedy konsola działa, ale SSH przez port NAT i internet w maszynie nie.
  Agent przy każdym starcie dodaje na początek `FORWARD` dwie reguły dla mostka
  `vhnat0` (`scripts/nat-forward.sh`: ruch z maszyn oraz odpowiedzi i ruch
  z przekierowanych portów do maszyn). firewalld z backendem nftables trzeba
  skonfigurować ręcznie (np. dodać `vhnat0` do strefy `trusted`);
- port z bloku musi być otwarty także w firewallu dostawcy (panel chmury,
  security group), jeśli węzeł za takim stoi;
- NAT IPv6 włącza `net.ipv6.conf.all.forwarding`, co wyłącza przyjmowanie
  ogłoszeń routera — host konfigurowany przez SLAAC potrzebuje `accept_ra=2`
  na interfejsie wyjściowym;
- po restarcie hosta agent odtwarza mostek i przekierowania z
  `/var/lib/virthub/nat/` przy starcie usługi.

#### Limit procesora

Pakiet może mieć `cpu_limit_percent` — twardy limit czasu procesora dla całej
maszyny w procentach jednego rdzenia, jak `cpulimit` w Proxmoksie ×100
(150 = półtora rdzenia, najwyżej vCPU × 100). Personel może go zmienić dla
pojedynczej maszyny w zakładce Ustawienia. Zmiana działa od razu, bez restartu:
KVM dostaje `<cputune><global_quota>` (cgroup całej domeny), a kontener
`limits.cpu.allowance` (np. `150ms/100ms`). Zmiana pakietu ustawia limit nowego
pakietu.

### 5. Sprawdź łączność

```bash
cd /var/www/virthub/control-plane
php artisan virthub:poll-hypervisors
```

Oczekiwane: `node1: libvirt, 0 VM, wolne … MB RAM / … GB dysku`.

Jeśli widzisz `libvirt` zamiast `mock`, agent działa na prawdziwym hypervisorze
i można zamówić pierwszą testową maszynę.

---

## Lista kontrolna przed wpuszczeniem klientów

- [ ] `APP_DEBUG=false` i `APP_ENV=production`
- [ ] HTTPS na panelu i na agencie
- [ ] Worker kolejki działa (`supervisorctl status`)
- [ ] Cron harmonogramu działa (`php artisan schedule:list`)
- [ ] Seeder **nie** został uruchomiony na produkcji
- [ ] Konta `admin@virthub.test` i `klient@virthub.test` nie istnieją w bazie
- [ ] Dostęp do portu agenta ograniczony do adresu panelu
- [ ] Zegary zsynchronizowane przez NTP na obu maszynach
- [ ] Backup bazy panelu (utrata = utrata przypisań maszyn do klientów)
- [ ] Pierwsza testowa maszyna zamówiona, uruchomiona i usunięta bez błędów
