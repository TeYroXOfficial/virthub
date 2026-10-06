# VirtHub — serwer licencji

Osobna aplikacja (Laravel 12) wydająca licencje paneli VirtHub i dystrybuująca
płatne addony. Panel VirtHub łączy się z nią przez HTTPS — szczegóły w
[`docs/addony-licencje.md`](../docs/addony-licencje.md).

## Co robi

- **Licencje** `VH-XXXX-XXXX-XXXX-XXXX`: właściciel, domena panelu (przypinana przy
  pierwszej aktywacji), stan, ważność, przypisane addony (z własną datą ważności).
- **Addony i wersje**: wgranie paczki zip → serwer sprawdza `addon.json`, liczy
  SHA-256 i podpisuje ją kluczem **Ed25519**. Wycofanie wersji = panele dostają poprzednią.
- **API dla paneli** (`/api/v1`): `license/verify` (podpisany token licencji),
  `addons` (katalog), `addons/{id}/download` (paczka + podpis), `public-key`.
- Dziennik zdarzeń licencji (aktywacje, zmiany domeny, pobrania).

## Wdrożenie

```bash
git clone https://github.com/TeYroXOfficial/virthub.git /opt/virthub-license
cd /opt/virthub-license/license-server
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# .env: APP_URL=https://license.twojadomena.pl, baza (sqlite wystarczy na start)
php artisan migrate --force
php artisan license:keygen          # zapisuje LICENSE_SIGNING_KEY w .env, wypisuje klucz publiczny
php artisan license:admin ty@twojadomena.pl
```

nginx jak dla każdej aplikacji Laravel (`root …/license-server/public`, PHP-FPM, HTTPS).
**Zrób kopię zapasową `LICENSE_SIGNING_KEY`** — jego zmiana unieważnia podpisy we
wszystkich panelach (trzeba by podmienić klucz publiczny w każdym).

### W panelu VirtHub (`control-plane/.env`)

```
VIRTHUB_LICENSE_SERVER=https://license.twojadomena.pl
VIRTHUB_LICENSE_PUBLIC_KEY=<klucz publiczny z license:keygen>
```

Potem Administracja → System → **Licencja i addony** → klucz licencji.

## Publikacja addonu z wiersza poleceń

```bash
php artisan license:publish onidel-1.0.0.zip --name="Reselling Onidel" --changelog="Pierwsza wersja"
```

Identyfikator i wersja pochodzą z `addon.json` paczki. To samo można zrobić w
panelu (Addony → Wgraj wersję).

## Testy

```bash
php artisan test
```
