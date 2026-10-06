<?php

namespace App\Domain\Apps\Databases;

use App\Models\AppDatabase;
use App\Models\AppServer;
use App\Models\AuditLog;
use App\Models\DatabaseHost;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Bazy danych aplikacji: zakładanie, hasła, usuwanie, limity. */
class DatabaseManager
{
    /** Dozwolony host połączeń: % albo adres/wzorzec (np. 203.0.113.%). */
    public const REMOTE_PATTERN = '/^[A-Za-z0-9.%:_-]{1,64}$/';

    public function __construct(private readonly DatabaseServer $server) {}

    public function limit(AppServer $app): int
    {
        return (int) ($app->database_limit ?? $app->plan?->databases ?? 0);
    }

    /** Serwer dla nowej bazy: najpierw przypisany do węzła aplikacji, potem ogólny; z wolnym miejscem. */
    public function pickHost(AppServer $app): ?DatabaseHost
    {
        return DatabaseHost::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('hypervisor_id')->orWhere('hypervisor_id', $app->hypervisor_id))
            ->withCount('databases')->get()
            ->filter(fn (DatabaseHost $h) => $h->max_databases === null || $h->databases_count < $h->max_databases)
            ->sortBy(fn (DatabaseHost $h) => [$h->hypervisor_id === $app->hypervisor_id ? 0 : 1, $h->databases_count])
            ->first();
    }

    public function create(AppServer $app, string $name, string $remote = '%', ?User $actor = null, bool $staff = false, ?DatabaseHost $host = null): AppDatabase
    {
        if (! preg_match('/^[a-z0-9_]{1,40}$/', $name)) {
            throw new DatabaseException(__('Nazwa bazy: małe litery, cyfry i podkreślenie, do 40 znaków.'));
        }
        if (! preg_match(self::REMOTE_PATTERN, $remote)) {
            throw new DatabaseException(__('Nieprawidłowy host połączeń — użyj % albo adresu IP.'));
        }
        if (! $staff && $app->databases()->count() >= $this->limit($app)) {
            throw new DatabaseException(__('Osiągnięto limit :limit baz danych tej aplikacji.', ['limit' => $this->limit($app)]));
        }
        $host ??= $this->pickHost($app) ?? throw new DatabaseException(__('Brak dostępnego serwera baz danych — skontaktuj się z obsługą.'));

        $database = 's'.$app->id.'_'.$name;
        if (AppDatabase::query()->where('database_host_id', $host->id)->where('database', $database)->exists()) {
            throw new DatabaseException(__('Baza :name już istnieje.', ['name' => $database]));
        }
        $username = 'u'.$app->id.'_'.Str::lower(Str::random(8));
        $password = Str::password(24, symbols: false);

        $this->server->createDatabase($host, $database, $username, $password, $remote);
        $db = new AppDatabase(['app_server_id' => $app->id, 'database_host_id' => $host->id, 'database' => $database, 'username' => $username, 'remote' => $remote]);
        $db->setSecret($password);
        $db->save();
        AuditLog::record('app.database_created', $app, ['database' => $database, 'host' => $host->name], $actor);

        return $db;
    }

    public function rotatePassword(AppDatabase $db, ?User $actor = null): string
    {
        $password = Str::password(24, symbols: false);
        $this->server->changePassword($db->host, $db->username, $db->remote, $password);
        $db->setSecret($password);
        $db->save();
        AuditLog::record('app.database_password', $db->app, ['database' => $db->database], $actor);

        return $password;
    }

    public function delete(AppDatabase $db, ?User $actor = null): void
    {
        $this->server->dropDatabase($db->host, $db->database, $db->username, $db->remote);
        AuditLog::record('app.database_deleted', $db->app, ['database' => $db->database], $actor);
        $db->delete();
    }

    /** Przy usuwaniu aplikacji — błąd serwera bazy nie blokuje usunięcia (trafia do logu). */
    public function deleteAllFor(AppServer $app, ?User $actor = null): void
    {
        foreach ($app->databases()->with('host')->get() as $db) {
            try {
                $this->delete($db, $actor);
            } catch (Throwable $e) {
                Log::warning('Nie udało się usunąć bazy aplikacji', ['app' => $app->id, 'database' => $db->database, 'error' => $e->getMessage()]);
                AuditLog::record('app.database_orphaned', $app, ['database' => $db->database, 'host' => $db->host?->name, 'error' => $e->getMessage()], $actor);
                DB::table('app_databases')->where('id', $db->id)->delete();
            }
        }
    }

    public function size(AppDatabase $db): ?int
    {
        try {
            return $this->server->size($db->host, $db->database);
        } catch (Throwable) {
            return null;
        }
    }
}
