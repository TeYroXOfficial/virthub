<?php

namespace App\Domain\System;

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Kopia systemowa panelu: zrzut bazy, plik .env (z APP_KEY — bez niego
 * zaszyfrowane hasła i klucze w bazie są nie do odczytania) i storage/app
 * (załączniki, awatary, klucze) w jednym archiwum tar.gz.
 *
 * Archiwa leżą w storage/app/system-backups z prawami 0600; najstarsze ponad
 * limit są usuwane. Odtworzenie: docs/system-backup.md.
 */
class SystemBackup
{
    public const PATTERN = '/^virthub-panel-\d{8}-\d{6}(-\d{1,3})?\.tar\.gz$/';

    public static function directory(): string
    {
        return storage_path('app/system-backups');
    }

    public static function keep(): int
    {
        return max(1, (int) Setting::get('system_backup.keep', config('virthub.system_backup_keep', 7)));
    }

    public static function dailyEnabled(): bool
    {
        return (bool) Setting::get('system_backup.daily', false);
    }

    /** @return list<array{name: string, size: int, created_at: int}> najnowsze pierwsze */
    public function list(): array
    {
        $dir = self::directory();
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $file) {
            if (preg_match(self::PATTERN, $file)) {
                $out[] = ['name' => $file, 'size' => (int) filesize("$dir/$file"), 'created_at' => (int) filemtime("$dir/$file")];
            }
        }
        usort($out, fn ($a, $b) => [$b['created_at'], $b['name']] <=> [$a['created_at'], $a['name']]);

        return $out;
    }

    public function path(string $name): ?string
    {
        $path = self::directory().'/'.basename($name);

        return preg_match(self::PATTERN, basename($name)) && is_file($path) ? $path : null;
    }

    /** Tworzy archiwum i zwraca jego nazwę. */
    public function create(?\App\Models\User $actor = null): string
    {
        File::ensureDirectoryExists(self::directory(), 0700);
        $stamp = 'virthub-panel-'.now()->format('Ymd-His');
        $name = $stamp.'.tar.gz';
        for ($i = 2; is_file(self::directory().'/'.$name) && $i < 1000; $i++) {
            $name = "{$stamp}-{$i}.tar.gz"; // dwie kopie w tej samej sekundzie nie nadpisują się
        }
        $work = storage_path('app/system-backups/.work-'.Str::random(8));
        File::ensureDirectoryExists($work, 0700);
        try {
            $dump = $this->dumpDatabase($work);
            File::copy(base_path('.env'), "$work/env");
            file_put_contents("$work/manifest.json", json_encode([
                'format' => 'virthub-panel/1',
                'created_at' => now()->toIso8601String(),
                'app_url' => config('app.url'),
                'database' => ['driver' => config('database.connections.'.config('database.default').'.driver'), 'file' => basename($dump)],
                'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $tar = "$work/archive.tar";
            $phar = new \PharData($tar);
            $phar->addFile("$work/manifest.json", 'manifest.json');
            $phar->addFile("$work/env", '.env');
            $phar->addFile($dump, 'database/'.basename($dump));
            $app = storage_path('app');
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = substr($file->getPathname(), strlen($app) + 1);
                if ($file->isFile() && ! str_starts_with($relative, 'system-backups')) {
                    $phar->addFile($file->getPathname(), 'storage/app/'.$relative);
                }
            }
            $phar->compress(\Phar::GZ);
            unset($phar);
            $target = self::directory().'/'.$name;
            rename("$tar.gz", $target);
            chmod($target, 0600);
        } finally {
            File::deleteDirectory($work);
        }
        AuditLog::record('system.backup_created', null, ['name' => $name, 'size' => filesize(self::directory().'/'.$name)], $actor);
        $this->prune();

        return $name;
    }

    public function delete(string $name, ?\App\Models\User $actor = null): bool
    {
        $path = $this->path($name);
        if ($path === null) {
            return false;
        }
        unlink($path);
        AuditLog::record('system.backup_deleted', null, ['name' => $name], $actor);

        return true;
    }

    public function prune(): int
    {
        $removed = 0;
        foreach (array_slice($this->list(), self::keep()) as $old) {
            @unlink(self::directory().'/'.$old['name']);
            $removed++;
        }

        return $removed;
    }

    private function sqliteSqlDump(string $connection, string $target): string
    {
        $pdo = DB::connection($connection)->getPdo();
        $out = fopen($target, 'w');
        fwrite($out, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
        foreach ($pdo->query("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%'")->fetchAll(\PDO::FETCH_ASSOC) as $table) {
            fwrite($out, $table['sql'].";\n");
            $rows = $pdo->query('select * from "'.str_replace('"', '""', $table['name']).'"');
            foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $values = array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row);
                fwrite($out, 'INSERT INTO "'.str_replace('"', '""', $table['name']).'" VALUES('.implode(',', $values).");\n");
            }
        }
        foreach ($pdo->query("select sql from sqlite_master where type in ('index', 'trigger') and sql is not null")->fetchAll(\PDO::FETCH_COLUMN) as $sql) {
            fwrite($out, $sql.";\n");
        }
        fwrite($out, "COMMIT;\n");
        fclose($out);

        return $target;
    }

    /** Zrzut bazy do katalogu roboczego; zwraca ścieżkę pliku. */
    private function dumpDatabase(string $work): string
    {
        $connection = config('database.default');
        $c = config("database.connections.$connection");
        switch ($c['driver']) {
            case 'sqlite':
                $target = "$work/database.sqlite";
                try {
                    // VACUUM INTO: spójna kopia działającej bazy (bez blokowania zapisów na czas kopiowania pliku).
                    DB::connection($connection)->statement('VACUUM INTO ?', [$target]);

                    return $target;
                } catch (\Throwable) {
                    // Otwarta transakcja albo stary SQLite — zrzut SQL przez PDO.
                    return $this->sqliteSqlDump($connection, "$work/database.sql");
                }
            case 'mysql':
            case 'mariadb':
                $target = "$work/database.sql";
                $result = Process::env(['MYSQL_PWD' => (string) ($c['password'] ?? '')])->timeout(3600)->run([
                    'mysqldump', '--single-transaction', '--routines', '--no-tablespaces', '--default-character-set=utf8mb4',
                    '-h', (string) ($c['host'] ?? '127.0.0.1'), '-P', (string) ($c['port'] ?? 3306), '-u', (string) ($c['username'] ?? 'root'),
                    '--result-file='.$target, (string) $c['database'],
                ]);
                break;
            case 'pgsql':
                $target = "$work/database.sql";
                $result = Process::env(['PGPASSWORD' => (string) ($c['password'] ?? '')])->timeout(3600)->run([
                    'pg_dump', '--no-owner', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-p', (string) ($c['port'] ?? 5432),
                    '-U', (string) ($c['username'] ?? 'postgres'), '-f', $target, (string) $c['database'],
                ]);
                break;
            default:
                throw new \RuntimeException(__('Kopia bazy :driver nie jest obsługiwana.', ['driver' => $c['driver']]));
        }
        if (! $result->successful()) {
            throw new \RuntimeException(__('Zrzut bazy nie powiódł się: :error', ['error' => Str::limit(trim($result->errorOutput()), 500)]));
        }

        return $target;
    }
}
