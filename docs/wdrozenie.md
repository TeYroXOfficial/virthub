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
być routowane na serwer). Tryb NAT z prywatną adresacją nie jest jeszcze
obsługiwany.

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
```

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

---

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
