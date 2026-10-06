# Licencje, addony i reselling usług innych dostawców — plan

## Cel

Właściciel instancji VirtHub ma **licencję**. Do licencji może dokupić **addony**,
np. „Reselling Onidel”: wtedy w jego panelu klienci kupują VPS-y, które fizycznie
powstają u Onidela (albo innego dostawcy), ale wyglądają i rozliczają się jak
zwykłe VPS-y VirtHub.

## Elementy

```
┌──────────────────────────┐   HTTPS (klucz licencji)   ┌───────────────────────────┐
│ Panel VirtHub klienta     │ ─────────────────────────► │ Serwer licencji (Twój)    │
│  • LicenseManager         │ ◄───────────────────────── │  license-server/          │
│  • AddonManager           │   podpisany token licencji │  • licencje, domeny       │
│  • sterowniki dostawców   │   + podpisane paczki       │  • addony i wersje        │
└──────────────────────────┘                             │  • klucz prywatny Ed25519 │
            │ API dostawcy (token właściciela)            └───────────────────────────┘
            ▼
   Onidel / Hetzner / …
```

### 1. Serwer licencji — `license-server/` (osobna aplikacja Laravel, Twój serwer)

- **Licencje**: klucz (`VH-XXXX-…`), właściciel, domena panelu (przypięcie przy
  pierwszej aktywacji, zmiana przez Ciebie), status, data ważności, addony.
- **Addony**: identyfikator (`onidel`), nazwa, opis, cena, wersje. Wgranie wersji
  (zip) → serwer liczy SHA-256 i podpisuje paczkę kluczem prywatnym **Ed25519**.
- **API** (dla paneli):
  - `POST /api/v1/license/verify` → **token licencji** podpisany Ed25519:
    `{key, domain, status, expires_at, addons:[{id, version}], issued_at, valid_until}`,
  - `GET /api/v1/addons` → katalog (co jest, co ma licencja),
  - `POST /api/v1/addons/{id}/download` → paczka + podpis (tylko gdy licencja ma addon).
- Panel administratora (Ty): licencje, addony, wgrywanie wersji. Sprzedaż (Stripe)
  w kolejnym etapie — na start addony przypisujesz ręcznie.

### 2. Panel VirtHub — klient licencji i addonów (publiczne repo)

- **Administracja → Licencja i addony**: klucz licencji, stan, data ważności,
  lista addonów (zainstaluj / aktualizuj / wyłącz).
- **LicenseManager**: co 12 h odświeża token; panel ufa tylko tokenowi z poprawnym
  podpisem (klucz publiczny serwera licencji wbudowany w panel). Brak łączności →
  **7 dni łaski** na ostatnim ważnym tokenie, potem addony się wyłączają (działające
  serwery klientów zostają nietknięte, blokowane są tylko nowe zamówienia i akcje).
- **AddonManager**: pobiera paczkę, sprawdza podpis Ed25519 i SHA-256, rozpakowuje
  do `storage/addons/{id}/{version}` (poza repo, przetrwa aktualizacje panelu),
  ładuje kod (PSR-4 `VirtHubAddons\{Id}\`) i jego `ServiceProvider` — tylko gdy
  licencja ma ten addon.
- **Punkty rozszerzeń** (stabilne API dla addonów):
  - `ProviderDriver` — sterownik dostawcy chmury (pierwszy punkt),
  - kolejne w miarę potrzeb (bramki płatności, moduły DNS…).

### 3. Reselling — serwery zewnętrzne

- **Konto dostawcy** (Administracja → Dostawcy zewnętrzni): addon + token API
  właściciela (szyfrowany `APP_KEY`), test połączenia.
- **Produkt typu „VPS zewnętrzny”**: konto dostawcy, lokalizacja, typ instancji
  (CPU/RAM/dysk), dozwolone systemy — katalog pobierany z API dostawcy; cena
  i cykle jak w każdym produkcie VirtHub (Twoja marża).
- **ExternalServer**: zamówienie → billing → sterownik tworzy maszynę → panel
  odpytuje stan do „active”, zapisuje IP i hasło.
- **Strona serwera klienta** jak przy VPS: stan, IP, hasło root, zasilanie
  (stop / reboot / start), reinstalacja, konsola (noVNC dostawcy), rDNS,
  zmiana nazwy. Klient nie widzi nazwy dostawcy.
- Zawieszenie usługi → stop maszyny; usunięcie → destroy u dostawcy.

### 4. Addon Onidel (prywatne repo)

API `https://api.cloud.onidel.com`, `Authorization: Bearer <token>`:
`GET /instance_types`, `GET /os_templates`, `POST /vm` (name, location,
instance_type, cpu, ram, disk, os, payment_cycle `hourly`, ssh_keys…),
`GET /vm/{id}` (status `building|active|suspended|…`, `main_ipv4/6`, `password`),
`POST /vm/{id}/stop|reboot`, `PATCH /vm/{id}` (`os_id` → reinstalacja, `name`),
`POST /vm/{id}/vnc` (`vnc_url`, ważny 30 s), `GET/POST/DELETE /vm/{id}/rdns`,
`DELETE /vm/{id}`.

## Bezpieczeństwo

- Klucz prywatny Ed25519 jest wyłącznie na serwerze licencji; panel ma tylko
  publiczny — nie da się podrobić tokenu licencji ani paczki addonu.
- Paczka jest instalowana tylko po zgodności podpisu i SHA-256.
- Kod addonów nie trafia do publicznego repo — dostępny jedynie przez serwer
  licencji dla licencji z wykupionym addonem.
- Token API dostawcy szyfrowany w bazie panelu; nigdy nie trafia do klienta.

## Etapy

1. Panel: licencja + menedżer addonów + interfejs `ProviderDriver` + serwery
   zewnętrzne (testy na atrapie sterownika).
2. Serwer licencji `license-server/`.
3. Addon Onidel w prywatnym repo + skrypt budowania i podpisywania paczek.
4. Sprzedaż addonów i licencji (Stripe) na serwerze licencji; kolejni dostawcy.
