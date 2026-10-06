<?php

namespace App\Domain\Apps\Databases;

use App\Models\AppDatabase;
use PDO;
use PDOException;

/**
 * Przeglądarka bazy w panelu. Łączy się KONTEM KLIENTA (nie administracyjnym),
 * więc serwer bazy sam pilnuje, żeby widział i zmieniał tylko swoją bazę.
 */
class DatabaseBrowser
{
    public const MAX_ROWS = 500;

    private const TIMEOUT = 15; // sekundy na zapytanie

    private PDO $pdo;

    public function __construct(private readonly AppDatabase $db)
    {
        if (! $db->browsable()) {
            throw new DatabaseException(__('Przeglądarka wymaga dostępu z dowolnego hosta (%). Ta baza przyjmuje połączenia tylko z :remote.', ['remote' => $db->remote]));
        }
        $host = $db->host;
        $this->pdo = MysqlDatabaseServer::connect($host->host, $host->port, $db->username, $db->secret(), $db->database);
        // Limit czasu zapytania — MariaDB i MySQL mają różne zmienne.
        foreach (['SET SESSION max_statement_time = '.self::TIMEOUT, 'SET SESSION MAX_EXECUTION_TIME = '.(self::TIMEOUT * 1000)] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException) {
            }
        }
    }

    /** @return list<array{name:string, rows:int, size:int, engine:?string}> */
    public function tables(): array
    {
        $stmt = $this->pdo->prepare('SELECT table_name AS name, table_rows AS `rows`, data_length + index_length AS size, engine, table_type AS type
            FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name');
        $stmt->execute([$this->db->database]);

        return array_map(fn ($r) => ['name' => $r['name'], 'rows' => (int) $r['rows'], 'size' => (int) $r['size'], 'engine' => $r['engine'], 'type' => $r['type']], $stmt->fetchAll());
    }

    public function hasTable(string $table): bool
    {
        return in_array($table, array_column($this->tables(), 'name'), true);
    }

    /** @return list<array<string, mixed>> */
    public function columns(string $table): array
    {
        $this->assertTable($table);

        return $this->pdo->query('SHOW FULL COLUMNS FROM '.MysqlDatabaseServer::ident($table))->fetchAll();
    }

    /** @return array{columns: list<string>, rows: list<array<string, mixed>>, total: int} */
    public function rows(string $table, int $page, int $perPage = 50, ?string $orderBy = null, string $dir = 'asc'): array
    {
        $this->assertTable($table);
        $columns = array_column($this->columns($table), 'Field');
        $t = MysqlDatabaseServer::ident($table);
        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        $order = $orderBy !== null && in_array($orderBy, $columns, true)
            ? ' ORDER BY '.MysqlDatabaseServer::ident($orderBy).($dir === 'desc' ? ' DESC' : ' ASC') : '';
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->pdo->query("SELECT * FROM {$t}{$order} LIMIT {$perPage} OFFSET {$offset}")->fetchAll();

        return ['columns' => $columns, 'rows' => $rows, 'total' => $total];
    }

    /**
     * Konsola SQL: zapytania po kolei; wynik ostatniego zwracającego wiersze
     * (do 500) albo liczba zmienionych wierszy.
     *
     * @return array{statements:int, columns: list<string>, rows: list<array<string, mixed>>, affected:int, truncated:bool, ms:int}
     */
    public function run(string $sql): array
    {
        $statements = SqlSplitter::split($sql);
        if ($statements === []) {
            throw new DatabaseException(__('Wpisz zapytanie SQL.'));
        }
        $result = ['statements' => count($statements), 'columns' => [], 'rows' => [], 'affected' => 0, 'truncated' => false, 'ms' => 0];
        $start = microtime(true);
        foreach ($statements as $i => $statement) {
            try {
                $stmt = $this->pdo->query($statement);
                if ($stmt->columnCount() > 0) {
                    $rows = [];
                    while (($row = $stmt->fetch()) !== false) {
                        if (count($rows) >= self::MAX_ROWS) {
                            $result['truncated'] = true;
                            break;
                        }
                        $rows[] = $row;
                    }
                    $stmt->closeCursor();
                    $result['columns'] = $rows !== [] ? array_keys($rows[0]) : $this->columnNames($stmt);
                    $result['rows'] = $rows;
                } else {
                    $result['affected'] += $stmt->rowCount();
                }
            } catch (PDOException $e) {
                throw new DatabaseException(__('Zapytanie :n: :error', ['n' => $i + 1, 'error' => MysqlDatabaseServer::clean($e)]));
            }
        }
        $result['ms'] = (int) round((microtime(true) - $start) * 1000);

        return $result;
    }

    /** Import skryptu SQL; zatrzymuje się na pierwszym błędzie. @return int wykonane zapytania */
    public function import(string $sql): int
    {
        $done = 0;
        foreach (SqlSplitter::split($sql) as $i => $statement) {
            if (preg_match('/^\s*(DELIMITER|USE)\b/i', $statement)) {
                continue; // zrzut innej bazy / procedury — import trafia zawsze do tej bazy
            }
            try {
                $this->pdo->exec($statement);
                $done++;
            } catch (PDOException $e) {
                throw new DatabaseException(__('Import przerwany na zapytaniu :n (wykonano :done): :error', ['n' => $i + 1, 'done' => $done, 'error' => MysqlDatabaseServer::clean($e)]));
            }
        }

        return $done;
    }

    /** Eksport struktury i danych jako SQL — kawałkami do wyjścia (bez ładowania całej bazy do pamięci). */
    public function export(callable $write): void
    {
        $write("-- VirtHub: eksport bazy {$this->db->database} — ".now()->toDateTimeString()."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        foreach ($this->tables() as $table) {
            $t = MysqlDatabaseServer::ident($table['name']);
            if ($table['type'] === 'VIEW') {
                $create = $this->pdo->query("SHOW CREATE VIEW {$t}")->fetch();
                $write("DROP VIEW IF EXISTS {$t};\n".($create['Create View'] ?? '').";\n\n");

                continue;
            }
            $create = $this->pdo->query("SHOW CREATE TABLE {$t}")->fetch();
            $write("DROP TABLE IF EXISTS {$t};\n".($create['Create Table'] ?? '').";\n\n");
            for ($offset = 0; ; $offset += 500) {
                $rows = $this->pdo->query("SELECT * FROM {$t} LIMIT 500 OFFSET {$offset}")->fetchAll();
                if ($rows === []) {
                    break;
                }
                $cols = implode(', ', array_map([MysqlDatabaseServer::class, 'ident'], array_keys($rows[0])));
                $values = array_map(fn ($row) => '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $this->pdo->quote((string) $v), $row)).')', $rows);
                $write("INSERT INTO {$t} ({$cols}) VALUES\n".implode(",\n", $values).";\n");
            }
            $write("\n");
        }
        $write("SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    private function assertTable(string $table): void
    {
        if (! $this->hasTable($table)) {
            throw new DatabaseException(__('Tabela :name nie istnieje.', ['name' => $table]));
        }
    }

    /** @return list<string> */
    private function columnNames(\PDOStatement $stmt): array
    {
        $names = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $names[] = (string) ($stmt->getColumnMeta($i)['name'] ?? $i);
        }

        return $names;
    }
}
