# Wydania i aktualizacje (portal VirtHub)

Od wersji **1.0.0** każda aktualizacja panelu i węzłów przechodzi przez portal VirtHub
(repozytorium `TeYroXOfficial/virthub-portal`): sklep licencji, lista wydań i podpisane paczki.

## Wersje

- Plik [`VERSION`](../VERSION) — wersja w formacie [semver 2.0.0](https://semver.org/lang/pl/).
  CI odrzuca niepoprawny semver i brak sekcji w [`CHANGELOG.md`](../CHANGELOG.md).
- **PATCH** (`1.4.2 → 1.4.3`) — poprawki bez zmian zachowania.
  **MINOR** (`1.4 → 1.5`) — nowe funkcje, bez zmian niekompatybilnych.
  **MAJOR** (`1.x → 2.0`) — zmiany wymagające uwagi (panel ostrzega przed aktualizacją).
- Wersja z oznaczeniem przedpremierowym (`1.5.0-beta.1`, `1.5.0-rc.1`) trafia tylko do kanału **beta**.
- Portal przyjmuje tylko wersję **wyższą od wszystkich dotychczasowych** i nie pozwala podmienić
  opublikowanej paczki. Wycofane wydanie znika z aktualizacji, numer zostaje zajęty.

## Jak wydać nową wersję

1. Zmień `VERSION` i dopisz sekcję `## [X.Y.Z] — data` w `CHANGELOG.md`
   (linia `Security: yes` oznacza wydanie bezpieczeństwa).
2. Scal do `main`, potem otaguj: `git tag vX.Y.Z && git push origin vX.Y.Z`.
3. Workflow [`release.yml`](../.github/workflows/release.yml) sprawdza zgodność tagu z `VERSION`,
   buduje paczkę (`scripts/release.sh` → `git archive`) i publikuje ją w portalu.
   Wymaga sekretu `VIRTHUB_PORTAL_TOKEN` i zmiennej `VIRTHUB_PORTAL_URL` w ustawieniach repozytorium.

Lokalnie: `scripts/release.sh` (albo `scripts/release.sh v1.4.2`) zostawia paczkę i notatki w `dist/`.

## Jak panel i węzły się aktualizują

1. Panel z kluczem licencji pyta portal (`/api/v1/updates/check`) o wydania nowsze od swojej
   wersji w kanale licencji i pokazuje notatki wszystkich pominiętych wersji.
2. **Aktualizuj panel** — panel zapisuje opis wydania (wersja, adres, SHA-256, podpis) dla usługi
   `virthub-panel-update`. Usługa (root) pobiera paczkę, sprawdza sumę i **podpis Ed25519 kluczem z
   `/etc/virthub/panel.conf`** (zapisanym przy instalacji — panel nie ma do niego zapisu), odmawia
   cofnięcia wersji i uruchamia instalator z paczki.
3. **Węzły** aktualizują się do **tej samej wersji co panel**. Panel prosi portal o paczkę tej wersji
   i przekazuje ją agentowi; usługa `virthub-agent-update` (root) sprawdza podpis kluczem
   przypiętym w `/etc/virthub-agent/update.env` (`VH_RELEASE_PUBLIC_KEY`).
4. Zawieszona albo wygasła licencja nie dostaje aktualizacji; działające maszyny pracują dalej.

### Klucz portalu

Portal podpisuje wydania tym samym kluczem Ed25519 co tokeny licencji (`php artisan license:keygen`).
Klucz publiczny wpisz do [`node-agent/release-key.pub`](../node-agent/release-key.pub) i wydaj nową
wersję — węzły przypinają go przy instalacji albo pierwszej aktualizacji, panel — przy instalacji
(`--portal-key` albo z tego pliku). Zmiana klucza wymaga ręcznej edycji na serwerach.

## Instalacja panelu z portalu

Na stronie licencji w portalu jest gotowe polecenie:

```bash
curl -sSL https://PORTAL/install.sh | sudo bash -s -- --key VH-XXXX-XXXX-XXXX-XXXX --domain panel.example.com --email admin@example.com
```

Skrypt pobiera najnowsze wydanie z kanału licencji, sprawdza SHA-256 i podpis, rozpakowuje paczkę i
uruchamia `infra/install-panel.sh --source … --portal … --portal-key … --license-key …`.

## Licencja w panelu

- Panel co 12 godzin zgłasza portalowi wersję i **liczbę węzłów**.
- Limit węzłów pochodzi z licencji: przy pełnym limicie panel nie doda kolejnego węzła
  (Administracja → Hypervisory), działające węzły pracują dalej. Limit zwiększa się w portalu.
- Panel bez skonfigurowanego portalu (`VIRTHUB_LICENSE_SERVER` puste) to instalacja deweloperska:
  bez limitu węzłów, bez addonów, aktualizacje wprost z repozytorium.
