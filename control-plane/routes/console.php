<?php

use App\Domain\Apps\Databases\NodeDatabaseInstaller;
use App\Domain\External\ExternalServerManager;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Mail\Mailouts;
use App\Domain\Metrics\Alerts;
use App\Domain\Provisioning\DisasterRecovery;
use App\Domain\Provisioning\RemoteBackups;
use App\Domain\Provisioning\SelfService;
use App\Domain\Storage\Datastores;
use App\Domain\Tickets\TicketService;
use App\Domain\Webhooks\Webhooks;
use App\Models\ServerMetric;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Harmonogram
|--------------------------------------------------------------------------
| Wszystko poniżej jest bezpieczne przy nakładaniu się uruchomień —
| withoutOverlapping chroni przed drugą kopią zadania, gdy hypervisor
| odpowiada wolno.
*/

// Heartbeat floty. Częściej niż co minutę nie ma sensu: dobór node'a i tak
// sprawdza pojemność w bazie pod blokadą.
Schedule::command('virthub:poll-hypervisors')
    ->everyMinute()
    ->withoutOverlapping();

// Siatka bezpieczeństwa na wypadek zgubionego callbacku od agenta.
Schedule::command('virthub:reconcile-jobs')
    ->everyMinute()
    ->withoutOverlapping();

// Telemetria co minutę — wykres godzinowy ma wtedy 60 punktów, a tygodniowy
// i tak uśrednia je do godzin.
Schedule::command('virthub:collect-metrics')
    ->everyMinute()
    ->withoutOverlapping();

// Nowe luki w jądrze, QEMU i libvirt węzłów (OSV.dev) — świeżo ogłoszone CVE
// pojawiają się w Infrastruktura → Bezpieczeństwo bez aktualizacji panelu.
Schedule::command('virthub:scan-vulnerabilities')->everySixHours()->withoutOverlapping()->runInBackground();

// Kopia systemowa panelu (baza, .env, storage/app) — gdy włączona w Administracja → System → Kopia panelu.
Schedule::command('virthub:system-backup --if-scheduled')->dailyAt('03:17')->withoutOverlapping();

// Retencja surowych próbek: 8 dni — tyle potrzebuje wykres tygodniowy. Bez
// tego server_metrics rośnie w nieskończoność.
Schedule::call(function () {
    ServerMetric::where('sampled_at', '<', now()->subDays(8))->delete();
})->daily()->name('prune-metrics');

// Katalogi modpacków, pluginów i modów — klient dostaje listy z cache od
// razu, zamiast czekać na Modrinth/FTB/CurseForge/Hangar.
Schedule::command('virthub:warm-content')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Zgłoszenia z odpowiedzią personelu, na które klient nie odpisał przez
// ustawioną liczbę dni, zamykają się same (Administracja → Wsparcie).
Schedule::call(fn () => app(TicketService::class)->autoClose())
    ->hourly()->name('tickets-autoclose')->withoutOverlapping();

// Billing: opłaty godzinowe z portfela, faktury odnowień, przypomnienia,
// zawieszanie i usuwanie za brak płatności. Każdy krok jest idempotentny.
Schedule::command('virthub:billing')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Instalacje MariaDB zlecone z panelu (Aplikacje → Bazy danych) — dodanie
// serwera baz, gdy węzeł skończy, nawet jeśli nikt nie trzyma otwartej strony.
Schedule::call(function () {
    $installer = app(NodeDatabaseInstaller::class);
    if ($installer->pending()) {
        $installer->syncPending();
    }
})->everyMinute()->name('mariadb-installs')->withoutOverlapping();

// Licencja: świeży podpisany token co 12 h (bez kontaktu panel ufa staremu przez okres łaski).
Schedule::call(fn () => app(LicenseManager::class)->refresh())
    ->twiceDaily(3, 15)->name('license-refresh')->withoutOverlapping();

// VPS-y u dostawców zewnętrznych w trakcie tworzenia/operacji — stan, IP, hasło.
Schedule::call(fn () => app(ExternalServerManager::class)->syncTransitional())
    ->everyMinute()->name('external-servers-sync')->withoutOverlapping();

// Webhooki: ponowienia nieudanych dostarczeń i sprzątanie historii.
Schedule::call(fn () => app(Webhooks::class)->retryDue())
    ->everyMinute()->name('webhooks-retry')->withoutOverlapping();

// Mailouty: kolejna partia wiadomości.
Schedule::call(fn () => app(Mailouts::class)->process())
    ->everyMinute()->name('mailouts')->withoutOverlapping();

// Alerty: progi metryk maszyn i węzłów (Infrastruktura → Alerty).
Schedule::call(fn () => app(Alerts::class)->evaluate())
    ->everyMinute()->name('alerts')->withoutOverlapping();

// Samoobsługa: godzinowe opłaty pakietów zasobów z portfela.
Schedule::call(fn () => app(SelfService::class)->bill())
    ->everyFifteenMinutes()->name('self-service-billing')->withoutOverlapping();

// Zrzuty konfiguracji węzłów (Hypervisor → Disaster recovery).
Schedule::call(fn () => app(DisasterRecovery::class)->runDue())
    ->hourly()->name('dr-snapshots')->withoutOverlapping();

// Pojemność magazynów danych (Infrastruktura → Magazyny danych).
Schedule::call(fn () => app(Datastores::class)->refreshAll())
    ->everyFifteenMinutes()->name('datastores-refresh')->withoutOverlapping();

// Kopie na zewnętrzne cele według planów (Infrastruktura → Kopie zapasowe).
Schedule::call(fn () => app(RemoteBackups::class)->runDue())
    ->everyFifteenMinutes()->name('remote-backups')->withoutOverlapping();
