#!/usr/bin/env bash
#
# Instalator panelu VirtHub (control plane).
#
#   curl -sSL <adres-tego-skryptu> | sudo bash -s -- --domain panel.example.com
#
# Stawia komplet: PHP z rozszerzeniami, MariaDB, Redis, nginx, kolejkę zadań,
# harmonogram i certyfikat TLS. Nie wymaga edytowania żadnego pliku — wszystkie
# hasła są generowane i wypisane na końcu.
#
# Skrypt jest idempotentny: ponowne uruchomienie aktualizuje instalację,
# zachowując klucz aplikacji i hasło do bazy.

# -E: pułapka ERR działa także wewnątrz funkcji i podpowłok.
set -Eeuo pipefail

# Przy `set -e` skrypt kończy się na pierwszym błędzie — ale bez tej pułapki
# robi to po cichu, w połowie instalacji, bez słowa o tym, co poszło nie tak.
# Tak zgubiliśmy kiedyś hasło administratora: konto powstało, a podsumowanie
# z hasłem nigdy się nie wypisało.
trap 'rc=$?; printf "\n\033[0;31m  ✗ Instalator przerwał się w linii %s (kod %s):\033[0m\n    %s\n\n  Po usunięciu przyczyny uruchom instalator ponownie — kontynuuje bezpiecznie.\n\n" "$LINENO" "$rc" "$BASH_COMMAND" >&2' ERR

# --- ustawienia domyślne ----------------------------------------------------

DOMAIN=""
ADMIN_EMAIL=""
SOURCE_DIR=""
REPO_URL=""
TARBALL_URL=""
ROOT_DIR="/opt/virthub"
APP_DIR=""
SKIP_TLS=0
DB_NAME="virthub"
DB_USER="virthub"
BRAND="VirtHub"
BRAND_SET=0

log()   { printf '\n\033[0;36m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
ok()    { printf '\033[0;32m  ✓\033[0m %s\n' "$*"; }
warn()  { printf '\033[0;33m  !\033[0m %s\n' "$*"; }
die()   { printf '\n\033[0;31m  ✗ %s\033[0m\n\n' "$*" >&2; exit 1; }

# Bez `| head -c`: zamknięcie potoku przez head może zabić tr sygnałem SIGPIPE,
# a przy pipefail cały instalator razem z nim. Przycinamy w samym bashu.
secret() {
    local s
    s="$(openssl rand -base64 64 | tr -dc 'A-Za-z0-9')"
    printf '%s' "${s:0:${1:-24}}"
}

# Wartość z istniejącego .env — przy aktualizacji nie nadpisujemy ustawień,
# które ktoś świadomie zmienił (nazwa bazy, ścieżka do agenta, marka).
env_get() {
    local file="$1" key="$2" line
    [ -f "$file" ] || return 0
    # Brak klucza to normalny przypadek (starsza instalacja nie znała jeszcze
    # tego ustawienia). grep zwraca wtedy 1, co przy pipefail zabiłoby cały
    # instalator — dlatego wynik bierzemy osobno i zawsze kończymy sukcesem.
    line="$(grep "^${key}=" "$file" 2>/dev/null | tail -1)" || true
    [ -n "$line" ] || return 0
    printf '%s' "${line#*=}" | sed 's/^"\(.*\)"$/\1/'
}

# Pytanie zadajemy na terminalu, a nie na stdin — tam siedzi treść skryptu
# pobrana curlem.
ask() {
    local prompt="$1" answer=""
    [ -e /dev/tty ] || return 1
    printf '\033[0;36m?\033[0m %s ' "$prompt" > /dev/tty
    read -r answer < /dev/tty || return 1
    printf '%s' "$answer"
}

# --- argumenty --------------------------------------------------------------

while [ $# -gt 0 ]; do
    case "$1" in
        --domain)   DOMAIN="$2"; shift 2 ;;
        --email)    ADMIN_EMAIL="$2"; shift 2 ;;
        --source)   SOURCE_DIR="$2"; shift 2 ;;
        --repo)     REPO_URL="$2"; shift 2 ;;
        --tarball)  TARBALL_URL="$2"; shift 2 ;;
        --app-dir)  APP_DIR="$2"; shift 2 ;;
        --brand)    BRAND="$2"; BRAND_SET=1; shift 2 ;;
        --no-tls)   SKIP_TLS=1; shift ;;
        -h|--help)
            cat <<'HELPEOF'
Instalator panelu VirtHub.

  --domain DOMENA   adres panelu; bez niego panel działa po IP, bez HTTPS
  --email EMAIL     login administratora (domyślnie admin@DOMENA)
  --repo URL        pobierz kod z repozytorium git
  --tarball URL     pobierz kod z archiwum .tar.gz
  --source KATALOG  użyj kodu już wgranego na serwer
  --app-dir KATALOG gdzie leży aplikacja (domyślnie /opt/virthub/control-plane)
  --brand NAZWA     nazwa produktu widoczna w panelu
  --no-tls          nie wystawiaj certyfikatu

Ponowne uruchomienie aktualizuje instalację i zachowuje klucz, bazę i konto.
HELPEOF
            exit 0 ;;
        *) die "Nieznany argument: $1" ;;
    esac
done

# --- kontrola środowiska ----------------------------------------------------

[ "$(id -u)" -eq 0 ] || die "Uruchom jako root: curl -sSL … | sudo bash -s -- --domain …"

command -v apt-get >/dev/null 2>&1 \
    || die "Instalator obsługuje Debiana i Ubuntu. Na innych dystrybucjach zainstaluj ręcznie wg docs/wdrozenie.md."

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a           # Ubuntu potrafi zatrzymać apt pytaniem o restart usług
export COMPOSER_ALLOW_SUPERUSER=1

# --- gdzie leży aplikacja ---------------------------------------------------

# Standardowy układ to kopia całego repozytorium w /opt/virthub, z panelem w
# control-plane/ i kodem agenta obok, w node-agent/.
#
# Wcześniejsze, ręczne instalacje leżą w /var/www/virthub jako spłaszczona
# zawartość control-plane/. Tego katalogu NIGDY nie traktujemy jako korzenia
# repozytorium — jego rodzicem jest /var/www, w którym mogą stać inne strony.
LEGACY_DIR="/var/www/virthub"
MIGRATE_FROM=""

if [ -z "$APP_DIR" ]; then
    APP_DIR="$ROOT_DIR/control-plane"

    if [ ! -f "$APP_DIR/artisan" ] && [ -f "$LEGACY_DIR/artisan" ]; then
        if [ -n "$REPO_URL$TARBALL_URL$SOURCE_DIR" ]; then
            # Jest nowy kod — stawiamy go w standardowym miejscu i przenosimy
            # konfigurację (klucz aplikacji, bazę, hasła) ze starej instalacji.
            MIGRATE_FROM="$LEGACY_DIR"
        else
            # Brak nowego kodu — odświeżamy starą instalację tam, gdzie jest,
            # bez pobierania i bez ruszania plików poza nią.
            APP_DIR="$LEGACY_DIR"
        fi
    fi
fi

IS_UPDATE=0
[ -f "$APP_DIR/artisan" ] && IS_UPDATE=1

# --- domena -----------------------------------------------------------------

if [ -z "$DOMAIN" ]; then
    DOMAIN="$(ask 'Domena panelu (np. panel.example.com, Enter = adres IP):' || true)"
fi

PUBLIC_IP="$(curl -fsS --max-time 10 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"

if [ -z "$DOMAIN" ]; then
    DOMAIN="$PUBLIC_IP"
    SKIP_TLS=1
    warn "Bez domeny nie da się wystawić certyfikatu — panel będzie działał po HTTP."
fi

[ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL="admin@${DOMAIN}"

log "Instaluję panel $BRAND"
printf '    domena:      %s\n' "$DOMAIN"
printf '    katalog:     %s\n' "$APP_DIR"
printf '    administrator: %s\n' "$ADMIN_EMAIL"
[ "$IS_UPDATE" -eq 1 ] && printf '    tryb:        aktualizacja istniejącej instalacji\n'
[ -n "$MIGRATE_FROM" ] && printf '    tryb:        przeniesienie z %s (konfiguracja zostaje zachowana)\n' "$MIGRATE_FROM"

# --- pakiety systemowe ------------------------------------------------------

log "Instaluję pakiety systemowe"
apt-get update -qq

# Wersja PHP zależy od dystrybucji: Debian 13 ma 8.4, Ubuntu 22.04 tylko 8.1,
# a projekt wymaga co najmniej 8.2. Jeśli w repozytoriach nie ma nic dość
# nowego, dokładamy repozytorium z aktualnym PHP.
detect_php() {
    local v
    for v in 8.4 8.3 8.2; do
        if apt-cache show "php$v-fpm" >/dev/null 2>&1; then
            echo "$v"
            return 0
        fi
    done
    return 1
}

if ! PHP_V="$(detect_php)"; then
    warn "W repozytoriach nie ma PHP 8.2+. Dokładam repozytorium."
    apt-get install -y -qq curl ca-certificates lsb-release apt-transport-https gnupg >/dev/null

    if grep -qi ubuntu /etc/os-release; then
        apt-get install -y -qq software-properties-common >/dev/null
        add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1
    else
        curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
        echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
            > /etc/apt/sources.list.d/php.list
    fi

    apt-get update -qq
    PHP_V="$(detect_php)" || die "Nie udało się udostępnić PHP 8.2+ na tym systemie."
fi

ok "PHP $PHP_V"

# Debian nie ma pakietu mysql-server — MariaDB jest w pełni zgodna.
apt-get install -y -qq \
    "php$PHP_V-fpm" "php$PHP_V-cli" "php$PHP_V-mysql" "php$PHP_V-redis" \
    "php$PHP_V-mbstring" "php$PHP_V-xml" "php$PHP_V-curl" "php$PHP_V-zip" \
    "php$PHP_V-bcmath" "php$PHP_V-gd" "php$PHP_V-intl" \
    nginx mariadb-server redis-server supervisor cron \
    certbot python3-certbot-nginx python3-venv \
    git unzip curl openssl ca-certificates >/dev/null

ok "Pakiety zainstalowane"

for service in "php$PHP_V-fpm" mariadb redis-server supervisor cron; do
    systemctl enable --now "$service" >/dev/null 2>&1 || true
done
ok "Usługi uruchomione"

# --- port 80 ----------------------------------------------------------------

# Apache wjeżdża jako zależność innych pakietów i zajmuje port, przez co nginx
# nie wstaje, a certbot wywala się komunikatem, który niczego nie tłumaczy.
if systemctl is-active --quiet apache2 2>/dev/null; then
    warn "Apache zajmuje port 80 — wyłączam go na rzecz nginx."
    systemctl disable --now apache2 >/dev/null 2>&1 || true
fi

if ss -tlnp 2>/dev/null | grep -q ':80 ' && ! ss -tlnp 2>/dev/null | grep ':80 ' | grep -q nginx; then
    ss -tlnp | grep ':80 ' >&2
    die "Port 80 jest zajęty przez powyższy proces. Zatrzymaj go i uruchom instalator ponownie."
fi

# --- composer ---------------------------------------------------------------

if ! command -v composer >/dev/null 2>&1; then
    log "Instaluję Composera"
    curl -sS https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer
    ok "Composer $(composer --version --no-ansi 2>/dev/null | awk '{print $3}')"
fi

# --- kod aplikacji ----------------------------------------------------------

log "Przygotowuję kod aplikacji"

# Nigdy nie kasujemy istniejącego katalogu. Instalator uruchamiany jako root
# z `rm -rf` na ścieżce wyliczonej z heurystyki to przepis na utratę danych —
# jeśli katalog docelowy jest zajęty czymś obcym, zatrzymujemy się i pytamy.
ensure_root_dir_usable() {
    if [ -d "$ROOT_DIR" ] && [ -n "$(ls -A "$ROOT_DIR" 2>/dev/null)" ] \
       && [ ! -f "$ROOT_DIR/control-plane/artisan" ] && [ ! -d "$ROOT_DIR/.git" ]; then
        die "Katalog $ROOT_DIR istnieje i zawiera coś innego niż VirtHub.
     Nie nadpisuję go. Opróżnij go ręcznie albo wskaż inne miejsce przez --app-dir."
    fi
}

if [ -n "$SOURCE_DIR" ]; then
    # Wymagamy całego repozytorium, nie samego control-plane: bez katalogu
    # node-agent obok panel nie ma czego wydać instalatorowi hypervisora.
    [ -f "$SOURCE_DIR/control-plane/artisan" ] \
        || die "W $SOURCE_DIR nie ma kodu panelu. Wskaż katalog całego repozytorium
     (ten, w którym są podkatalogi control-plane/ i node-agent/)."
    ensure_root_dir_usable
    mkdir -p "$ROOT_DIR"
    cp -a "$SOURCE_DIR/." "$ROOT_DIR/"
    ok "Skopiowano z $SOURCE_DIR"
elif [ -n "$REPO_URL" ]; then
    if [ -d "$ROOT_DIR/.git" ]; then
        git -C "$ROOT_DIR" fetch --quiet --all
        git -C "$ROOT_DIR" reset --quiet --hard origin/HEAD 2>/dev/null \
            || git -C "$ROOT_DIR" pull --quiet
        ok "Zaktualizowano z $REPO_URL"
    else
        ensure_root_dir_usable
        # Klon do katalogu tymczasowego i dopiero potem na miejsce — przerwane
        # pobieranie nie zostawia w $ROOT_DIR połowy repozytorium.
        CLONE_TMP="$(mktemp -d /tmp/virthub-clone.XXXXXX)"
        git clone --quiet "$REPO_URL" "$CLONE_TMP/repo" \
            || die "Nie udało się pobrać repozytorium $REPO_URL."
        mkdir -p "$ROOT_DIR"
        cp -a "$CLONE_TMP/repo/." "$ROOT_DIR/"
        rm -rf "$CLONE_TMP"
        ok "Pobrano z $REPO_URL"
    fi
elif [ -n "$TARBALL_URL" ]; then
    ensure_root_dir_usable
    mkdir -p "$ROOT_DIR"
    curl -fsSL "$TARBALL_URL" | tar -xz -C "$ROOT_DIR" --strip-components=1
    ok "Rozpakowano archiwum"
elif [ "$IS_UPDATE" -eq 1 ]; then
    ok "Używam kodu już obecnego w $APP_DIR"
else
    die "Nie wiem, skąd wziąć kod panelu.
     Podaj jedno z:
       --repo https://github.com/uzytkownik/virthub.git
       --tarball https://przyklad.pl/virthub.tar.gz
       --source /sciezka/do/wgranego/katalogu"
fi

[ -f "$APP_DIR/artisan" ] || die "Po rozpakowaniu nie znalazłem $APP_DIR/artisan."

cd "$APP_DIR"

log "Instaluję zależności PHP (to potrwa 1-3 minuty)"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
ok "Zależności zainstalowane"

# --- baza danych ------------------------------------------------------------

log "Konfiguruję bazę danych"

OLD_ENV="$APP_DIR/.env"
# Przy przeniesieniu nowy katalog nie ma jeszcze .env — konfigurację bierzemy
# ze starej instalacji. Bez tego nowy klucz aplikacji unieważniłby zaszyfrowane
# tokeny hypervisorów, a nowe hasło odcięłoby dostęp do istniejącej bazy.
if [ ! -f "$OLD_ENV" ] && [ -n "$MIGRATE_FROM" ] && [ -f "$MIGRATE_FROM/.env" ]; then
    OLD_ENV="$MIGRATE_FROM/.env"
    ok "Przenoszę konfigurację z $OLD_ENV"
fi
DB_PASS=""

# Przy aktualizacji zachowujemy nazwę bazy, użytkownika i hasło — zmiana
# rozjechałaby się z uprawnieniami w bazie i panel przestałby działać.
# Tylko jeśli stara konfiguracja była na MySQL/MariaDB: .env developerski na
# SQLite ma w DB_DATABASE ścieżkę do pliku, a nie nazwę bazy.
case "$(env_get "$OLD_ENV" DB_CONNECTION)" in
    mysql|mariadb)
        DB_NAME="$(env_get "$OLD_ENV" DB_DATABASE)"; DB_NAME="${DB_NAME:-virthub}"
        DB_USER="$(env_get "$OLD_ENV" DB_USERNAME)"; DB_USER="${DB_USER:-virthub}"
        DB_PASS="$(env_get "$OLD_ENV" DB_PASSWORD)"
        [ -n "$DB_PASS" ] && ok "Używam bazy $DB_NAME z istniejącej konfiguracji"
        ;;
esac

[ -n "$DB_PASS" ] || DB_PASS="$(secret 28)"

mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "Baza $DB_NAME gotowa"

# --- konfiguracja aplikacji -------------------------------------------------

log "Zapisuję konfigurację"

SCHEME="https"
[ "$SKIP_TLS" -eq 1 ] && SCHEME="http"

# Klucz aplikacji szyfruje tokeny agentów w bazie — jego zmiana unieważniłaby
# wszystkie zarejestrowane hypervisory.
APP_KEY="$(env_get "$OLD_ENV" APP_KEY)"

# Marka: jawnie podana wygrywa, w przeciwnym razie zostaje dotychczasowa.
if [ "$BRAND_SET" -eq 0 ]; then
    OLD_BRAND="$(env_get "$OLD_ENV" VIRTHUB_BRAND)"
    [ -n "$OLD_BRAND" ] && BRAND="$OLD_BRAND"
fi

# Ścieżka do kodu agenta — domyślnie obok panelu (układ monorepo). Zachowujemy
# ją, jeśli ktoś wskazał inne miejsce.
# Wspólny sekret panelu i przekaźnika konsoli. Zachowujemy go przy
# aktualizacji — zmiana tylko rozłączyłaby otwarte konsole.
CONSOLE_SECRET="$(env_get "$OLD_ENV" VIRTHUB_CONSOLE_SECRET)"
[ -n "$CONSOLE_SECRET" ] || CONSOLE_SECRET="$(secret 48)"

AGENT_SOURCE_LINE=""
OLD_AGENT_SOURCE="$(env_get "$OLD_ENV" VIRTHUB_AGENT_SOURCE_PATH)"
[ -n "$OLD_AGENT_SOURCE" ] && AGENT_SOURCE_LINE="VIRTHUB_AGENT_SOURCE_PATH=$OLD_AGENT_SOURCE"

cat > "$APP_DIR/.env" <<ENVEOF
APP_NAME="$BRAND"
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=$SCHEME://$DOMAIN

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS

SESSION_DRIVER=redis
SESSION_LIFETIME=120
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

MAIL_MAILER=log

VIRTHUB_BRAND="$BRAND"
VIRTHUB_CONSOLE_SECRET=$CONSOLE_SECRET
VIRTHUB_SERVERS_PER_CUSTOMER=10
VIRTHUB_METRICS_RETENTION_DAYS=30
$AGENT_SOURCE_LINE
ENVEOF

chmod 640 "$APP_DIR/.env"

if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force --quiet
    ok "Wygenerowano klucz aplikacji"
else
    ok "Zachowano istniejący klucz aplikacji"
fi

php artisan migrate --force --quiet
ok "Migracje wykonane"

# --- konto administratora ---------------------------------------------------

# --if-none sprawia, że ponowne uruchomienie instalatora nie podmienia hasła
# działającemu administratorowi. Puste wyjście = konto już było.
ADMIN_PASS="$(php artisan virthub:create-admin --email="$ADMIN_EMAIL" --if-none --porcelain 2>/dev/null | tail -1)"

if [ -n "$ADMIN_PASS" ]; then
    ok "Utworzono konto administratora"
else
    ok "Konto administratora już istnieje — zostawiam bez zmian"
fi

# --- uprawnienia i cache ----------------------------------------------------

chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chown root:www-data "$APP_DIR/.env"

php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan view:cache --quiet
ok "Konfiguracja zbudowana"

# --- nginx ------------------------------------------------------------------

log "Konfiguruję nginx"

# Nagłówek Connection dla WebSocketów. Ten sam plik zapisuje instalator
# węzła — na serwerze, który jest i panelem, i węzłem, mapa jest jedna.
cat > /etc/nginx/conf.d/virthub-websocket.conf <<'MAPEOF'
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}
MAPEOF

cat > /etc/nginx/sites-available/virthub <<NGINXEOF
server {
    listen 80;
    server_name $DOMAIN;
    root $APP_DIR/public;

    index index.php;
    charset utf-8;
    client_max_body_size 32M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Konsola maszyn: WebSocket do przekaźnika (usługa virthub-console).
    location /console-ws/ {
        proxy_pass http://127.0.0.1:8090;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \$connection_upgrade;
        proxy_set_header Host \$host;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php$PHP_V-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_read_timeout 120s;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINXEOF

# Wewnętrzne wejście dla przekaźnika konsoli — tylko na loopbacku i tylko do
# wymiany sesji. Osobny serwer, bo publiczny może przekierowywać na HTTPS
# (certbot), a przekierowanie zamieniłoby POST w GET.
cat > /etc/nginx/sites-available/virthub-internal <<NGINXEOF
server {
    listen 127.0.0.1:8091;
    server_name virthub-internal;
    root $APP_DIR/public;

    location /api/internal/console/ {
        try_files \$uri /index.php?\$query_string;
    }

    location = /index.php {
        fastcgi_pass unix:/run/php/php$PHP_V-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location / {
        return 404;
    }
}
NGINXEOF

ln -sf /etc/nginx/sites-available/virthub-internal /etc/nginx/sites-enabled/virthub-internal
ln -sf /etc/nginx/sites-available/virthub /etc/nginx/sites-enabled/virthub
rm -f /etc/nginx/sites-enabled/default
nginx -t >/dev/null 2>&1 || die "Konfiguracja nginx jest niepoprawna."
systemctl enable --now nginx >/dev/null 2>&1
systemctl reload nginx
ok "nginx nasłuchuje na porcie 80"

# --- certyfikat -------------------------------------------------------------

if [ "$SKIP_TLS" -eq 0 ]; then
    log "Wystawiam certyfikat TLS"

    # Wszystkie rekordy A, nie tylko pierwszy wynik — `getent hosts` potrafi
    # oddać najpierw adres IPv6 i fałszywie uznać, że domena wskazuje gdzie
    # indziej. Domena bez rekordów to normalny przypadek, nie awaria.
    A_RECORDS="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ')" || true

    # Za proxy Cloudflare domena rozwiązuje się na adresy Cloudflare, więc
    # porównanie z adresem serwera nic nie mówi. Rozpoznajemy to po nagłówku.
    BEHIND_CLOUDFLARE=0
    if curl -sI --max-time 10 "http://$DOMAIN/" 2>/dev/null | grep -qi '^server: *cloudflare'; then
        BEHIND_CLOUDFLARE=1
    fi

    # Za Cloudflare przekierowanie na HTTPS robi Cloudflare. Gdyby robił je
    # też serwer, w trybie SSL 'Flexible' powstałaby nieskończona pętla
    # przekierowań — strona przestałaby działać w ogóle.
    REDIRECT_FLAG="--redirect"
    [ "$BEHIND_CLOUDFLARE" -eq 1 ] && REDIRECT_FLAG="--no-redirect"

    issue_certificate() {
        certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
                --email "$ADMIN_EMAIL" "$REDIRECT_FLAG" >/tmp/virthub-certbot.log 2>&1
    }

    if [ -z "$A_RECORDS" ]; then
        warn "Domena $DOMAIN nie ma rekordu A — pomijam certyfikat."
        warn "Dodaj rekord A wskazujący na $PUBLIC_IP i uruchom: certbot --nginx -d $DOMAIN"
        SKIP_TLS=1
    elif [ "$BEHIND_CLOUDFLARE" -eq 1 ]; then
        ok "Domena jest za proxy Cloudflare"
        if issue_certificate; then
            ok "Certyfikat wystawiony"
            warn "W Cloudflare ustaw SSL/TLS → tryb 'Full (strict)'. W trybie 'Full'"
            warn "bez certyfikatu na serwerze Cloudflare zwraca błąd 521."
        else
            warn "Certyfikat nie przeszedł weryfikacji przez Cloudflare."
            warn "Przełącz rekord $DOMAIN w Cloudflare na 'DNS only' (szara chmurka),"
            warn "uruchom: certbot --nginx -d $DOMAIN"
            warn "a potem wróć do proxy i ustaw SSL/TLS na 'Full (strict)'."
            SKIP_TLS=1
        fi
    elif ! printf ' %s' "$A_RECORDS" | grep -q " $PUBLIC_IP "; then
        warn "Domena $DOMAIN wskazuje na: $A_RECORDS— a ten serwer ma $PUBLIC_IP."
        warn "Popraw rekord A i uruchom: certbot --nginx -d $DOMAIN"
        SKIP_TLS=1
    elif issue_certificate; then
        ok "Certyfikat wystawiony, ruch przekierowany na HTTPS"
    else
        warn "Certbot nie zdołał wystawić certyfikatu (szczegóły: /tmp/virthub-certbot.log)."
        warn "Po usunięciu przyczyny: certbot --nginx -d $DOMAIN"
        SKIP_TLS=1
    fi

    # APP_URL musi odpowiadać rzeczywistości — trafia do poleceń instalacyjnych
    # hypervisorów i do konfiguracji agentów.
    if [ "$SKIP_TLS" -eq 1 ]; then
        sed -i "s|^APP_URL=.*|APP_URL=http://$DOMAIN|" "$APP_DIR/.env"
        php artisan config:cache --quiet
    fi
fi

# --- kolejka zadań ----------------------------------------------------------

log "Uruchamiam kolejkę zadań i harmonogram"

# Bez workera zamówiona maszyna zostaje w stanie „Tworzenie" na zawsze:
# zlecenie trafia do kolejki i nikt go nie odbiera.
cat > /etc/supervisor/conf.d/virthub-worker.conf <<SUPEOF
[program:virthub-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $APP_DIR/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/virthub-worker.log
stopwaitsecs=3600
SUPEOF

supervisorctl reread >/dev/null 2>&1 || true
supervisorctl update >/dev/null 2>&1 || true

# Workery trzymają kod w pamięci. Bez restartu po aktualizacji dalej
# wykonywałyby starą wersję — nowe typy zadań kończyłyby się błędem „klasa nie
# istnieje", a poprawione zachowanie by nie weszło. queue:restart kończy je
# łagodnie po bieżącym zadaniu, a supervisor podnosi świeże.
php "$APP_DIR/artisan" queue:restart --quiet 2>/dev/null || true
ok "Worker kolejki działa"

# --- przekaźnik konsoli ---------------------------------------------------

log "Uruchamiam przekaźnik konsoli"

# Przeglądarka nie łączy się z węzłami bezpośrednio (przypięte certyfikaty,
# podpisy HMAC) — robi to ta usługa na serwerze panelu. Szczegóły:
# console-proxy/console_proxy.py.
CONSOLE_DIR="$ROOT_DIR/console-proxy"
if [ -f "$CONSOLE_DIR/console_proxy.py" ]; then
    python3 -m venv "$CONSOLE_DIR/.venv"
    "$CONSOLE_DIR/.venv/bin/pip" install --quiet --upgrade pip
    "$CONSOLE_DIR/.venv/bin/pip" install --quiet -r "$CONSOLE_DIR/requirements.txt" \
        || die "Instalacja zależności przekaźnika konsoli nie powiodła się."

    install -d -m 0755 /etc/virthub
    cat > /etc/virthub/console-proxy.env <<ENVEOF
VH_PANEL_URL=http://127.0.0.1:8091
VH_CONSOLE_SECRET=$CONSOLE_SECRET
VH_LISTEN_HOST=127.0.0.1
VH_LISTEN_PORT=8090
ENVEOF
    chmod 600 /etc/virthub/console-proxy.env

    cat > /etc/systemd/system/virthub-console.service <<UNITEOF
[Unit]
Description=VirtHub — przekaźnik konsoli maszyn (WebSocket)
After=network-online.target nginx.service
Wants=network-online.target

[Service]
Type=exec
DynamicUser=yes
EnvironmentFile=/etc/virthub/console-proxy.env
ExecStart=$CONSOLE_DIR/.venv/bin/python $CONSOLE_DIR/console_proxy.py
Restart=always
RestartSec=3
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNITEOF

    systemctl daemon-reload
    systemctl enable virthub-console >/dev/null 2>&1
    systemctl restart virthub-console
    ok "Przekaźnik konsoli działa"
else
    warn "Brak $CONSOLE_DIR — konsola w przeglądarce nie będzie działać."
    warn "Zainstaluj panel z pełnego repozytorium (--repo), a nie ze spłaszczonej kopii."
fi

# Harmonogram odpowiada za heartbeat węzłów i uzgadnianie wyników zadań.
CRON_LINE="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"

# Na świeżym serwerze www-data nie ma crontaba: `crontab -l` zwraca błąd, a
# `grep -v` bez żadnych linii wejściowych też. Oba przypadki są w porządku,
# więc zbieramy istniejące wpisy osobno, zamiast w potoku, który przy
# pipefail przerwałby instalację.
EXISTING_CRON="$(crontab -u www-data -l 2>/dev/null || true)"
OTHER_CRON="$(printf '%s\n' "$EXISTING_CRON" | grep -v 'artisan schedule:run' || true)"
printf '%s\n%s\n' "$OTHER_CRON" "$CRON_LINE" | sed '/^$/d' | crontab -u www-data -
ok "Harmonogram dopisany do crona"

# --- sprawdzenie ------------------------------------------------------------

log "Sprawdzam instalację"

HTTP_CODE="$(curl -sk -o /dev/null -w '%{http_code}' "http://127.0.0.1" -H "Host: $DOMAIN" || echo 000)"
case "$HTTP_CODE" in
    200|302) ok "Panel odpowiada (HTTP $HTTP_CODE)" ;;
    *)       warn "Panel zwrócił HTTP $HTTP_CODE. Zajrzyj do $APP_DIR/storage/logs/laravel.log" ;;
esac

WORKERS="$(supervisorctl status virthub-worker:* 2>/dev/null | grep -c RUNNING || true)"
[ "${WORKERS:-0}" -gt 0 ] && ok "Workery kolejki: $WORKERS" || warn "Worker kolejki nie działa."

if systemctl is-active --quiet virthub-console 2>/dev/null; then
    ok "Przekaźnik konsoli działa"
else
    warn "Przekaźnik konsoli nie działa: journalctl -u virthub-console -n 30"
fi

# --- podsumowanie -----------------------------------------------------------

PANEL_URL="$SCHEME://$DOMAIN"
[ "$SKIP_TLS" -eq 1 ] && PANEL_URL="http://$DOMAIN"

echo
printf '\033[0;32m━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\033[0m\n'
printf '\033[0;32m  %s jest gotowy\033[0m\n' "$BRAND"
printf '\033[0;32m━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\033[0m\n'
echo
printf '  Panel:    %s\n' "$PANEL_URL"

if [ -n "$ADMIN_PASS" ]; then
    printf '  Login:    %s\n' "$ADMIN_EMAIL"
    printf '  Hasło:    \033[1m%s\033[0m\n' "$ADMIN_PASS"
    echo
    printf '  \033[0;33mZapisz hasło teraz — nie zostanie wyświetlone ponownie.\033[0m\n'
else
    EXISTING_ADMIN="$(mariadb -N -B -e "SELECT email FROM users WHERE role='admin' ORDER BY id LIMIT 1" "$DB_NAME" 2>/dev/null)" || true
    printf '  Login:    %s (konto istniało wcześniej)\n' "${EXISTING_ADMIN:-$ADMIN_EMAIL}"
    echo
    echo "  Nie pamiętasz hasła? Nowe wygenerujesz i zobaczysz poleceniem:"
    printf '    cd %s && php artisan virthub:create-admin --email=%s\n' "$APP_DIR" "${EXISTING_ADMIN:-$ADMIN_EMAIL}"
fi

if [ "$SKIP_TLS" -eq 1 ]; then
    echo
    printf '  \033[0;33mUWAGA: panel działa po HTTP, bez szyfrowania.\033[0m\n'
    printf '  Przez panel przechodzą hasła root maszyn i tokeny agentów.\n'
    printf '  Wskaż domenę na ten serwer i uruchom: certbot --nginx -d %s\n' "$DOMAIN"
fi

if [ -n "$MIGRATE_FROM" ]; then
    echo
    printf '  Panel działa teraz z %s.\n' "$APP_DIR"
    printf '  Stara kopia w %s nie jest już używana. Gdy sprawdzisz, że\n' "$MIGRATE_FROM"
    printf '  wszystko działa, możesz ją usunąć: rm -rf %s\n' "$MIGRATE_FROM"
fi

echo
echo "  Następny krok: zaloguj się, wejdź w Administracja → Hypervisory"
echo "  i dodaj pierwszy węzeł. Dostaniesz jedno polecenie do wklejenia"
echo "  na serwerze z KVM — reszta zrobi się sama."
echo
