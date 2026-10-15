# Kopia systemowa panelu

Administracja → System → **Kopia panelu** (albo `php artisan virthub:system-backup`) tworzy
`storage/app/system-backups/virthub-panel-RRRRMMDD-GGMMSS.tar.gz` z prawami 0600:

| W archiwum | Zawartość |
|---|---|
| `manifest.json` | data, adres panelu, sterownik bazy, wersja |
| `.env` | konfiguracja z `APP_KEY` — bez niego zaszyfrowane hasła i klucze w bazie są nie do odczytania |
| `database/database.sqlite` albo `database/database.sql` | spójny zrzut bazy (`VACUUM INTO`, `mysqldump --single-transaction`, `pg_dump`) |
| `storage/app/…` | pliki panelu: załączniki zgłoszeń, awatary, klucze |

Maszyny klientów są na węzłach — ich kopie robią plany kopii (Backup Manager), nie ta kopia.

Kopia dobowa (03:17) i liczba trzymanych archiwów ustawia się na tej samej stronie;
`VH_SYSTEM_BACKUP_KEEP` daje wartość domyślną. Archiwum zawiera wszystkie sekrety panelu —
kopiuj je poza serwer (np. `rclone copy storage/app/system-backups remote:panel`) w zaszyfrowane miejsce.

## Odtworzenie na nowym serwerze

1. Zainstaluj panel instalatorem w tej samej albo nowszej wersji.
2. `systemctl stop virthub-queue virthub-console` (i serwer WWW).
3. Rozpakuj: `tar -xzf virthub-panel-….tar.gz -C /tmp/restore`.
4. `cp /tmp/restore/.env /opt/virthub/control-plane/.env` (sprawdź `APP_URL`, dane bazy).
5. Baza:
   - SQLite: `cp /tmp/restore/database/database.sqlite /opt/virthub/control-plane/database/database.sqlite`
   - MariaDB/MySQL: `mysql -u … -p nazwa_bazy < /tmp/restore/database/database.sql`
   - PostgreSQL: `psql -U … -d nazwa_bazy -f /tmp/restore/database/database.sql`
6. `cp -a /tmp/restore/storage/app/. /opt/virthub/control-plane/storage/app/`
7. `php artisan migrate --force && php artisan optimize`, uruchom usługi.

Węzły łączą się z panelem dalej tymi samymi tokenami — jeśli zmienił się adres panelu,
zaktualizuj `VH_CONTROL_PLANE_URL` w `/etc/virthub/agent.env` na węzłach.
