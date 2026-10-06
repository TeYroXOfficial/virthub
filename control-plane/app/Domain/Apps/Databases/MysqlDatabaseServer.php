<?php

namespace App\Domain\Apps\Databases;

use App\Models\DatabaseHost;
use PDO;
use PDOException;

/**
 * MySQL/MariaDB przez PDO kontem administracyjnym serwera. Nazwy baz
 * i użytkowników generuje panel ([a-z0-9_]), mimo to zawsze je cytujemy.
 */
class MysqlDatabaseServer implements DatabaseServer
{
    public function version(DatabaseHost $host): string
    {
        return (string) $this->pdo($host)->query('SELECT VERSION()')->fetchColumn();
    }

    public function createDatabase(DatabaseHost $host, string $database, string $username, string $password, string $remote): void
    {
        $pdo = $this->pdo($host);
        $db = self::ident($database);
        $user = self::account($pdo, $username, $remote);
        $this->run(function () use ($pdo, $db, $user, $password) {
            $pdo->exec("CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            try {
                $pdo->exec("CREATE USER {$user} IDENTIFIED BY ".$pdo->quote($password));
                $pdo->exec("GRANT ALL PRIVILEGES ON {$db}.* TO {$user}");
                $pdo->exec('FLUSH PRIVILEGES');
            } catch (PDOException $e) {
                // Bez użytkownika baza jest bezużyteczna — sprzątamy.
                $pdo->exec("DROP DATABASE IF EXISTS {$db}");
                throw $e;
            }
        });
    }

    public function dropDatabase(DatabaseHost $host, string $database, string $username, string $remote): void
    {
        $pdo = $this->pdo($host);
        $db = self::ident($database);
        $user = self::account($pdo, $username, $remote);
        $this->run(function () use ($pdo, $db, $user) {
            $pdo->exec("DROP DATABASE IF EXISTS {$db}");
            $pdo->exec("DROP USER IF EXISTS {$user}");
            $pdo->exec('FLUSH PRIVILEGES');
        });
    }

    public function changePassword(DatabaseHost $host, string $username, string $remote, string $password): void
    {
        $pdo = $this->pdo($host);
        $user = self::account($pdo, $username, $remote);
        $this->run(fn () => $pdo->exec("ALTER USER {$user} IDENTIFIED BY ".$pdo->quote($password)));
    }

    public function size(DatabaseHost $host, string $database): int
    {
        $stmt = $this->pdo($host)->prepare('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = ?');
        $stmt->execute([$database]);

        return (int) $stmt->fetchColumn();
    }

    /** Połączenie (także dla przeglądarki — z kontem klienta i wybraną bazą). */
    public static function connect(string $host, int $port, string $username, string $password, ?string $database = null): PDO
    {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4".($database ? ";dbname={$database}" : '');
        try {
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new DatabaseException(__('Nie można połączyć się z serwerem bazy: :error', ['error' => self::clean($e)]));
        }
    }

    /** `nazwa` — identyfikator w odwrotnych apostrofach. */
    public static function ident(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private static function account(PDO $pdo, string $username, string $remote): string
    {
        return $pdo->quote($username).'@'.$pdo->quote($remote);
    }

    private function pdo(DatabaseHost $host): PDO
    {
        return self::connect($host->host, $host->port, $host->username, $host->secret());
    }

    private function run(callable $fn): void
    {
        try {
            $fn();
        } catch (PDOException $e) {
            throw new DatabaseException(__('Serwer bazy odrzucił operację: :error', ['error' => self::clean($e)]));
        }
    }

    /** Komunikat PDO bez kodu SQLSTATE na początku. */
    public static function clean(PDOException $e): string
    {
        return trim(preg_replace('/^SQLSTATE\[[^\]]*\]:?\s*(\[[^\]]*\])?\s*(\d+\s*)?/', '', $e->getMessage()));
    }
}
