<?php

namespace App\Domain\Apps\Databases;

use App\Models\DatabaseHost;

/** Operacje administracyjne na serwerze baz (MySQL/MariaDB). */
interface DatabaseServer
{
    /** Wersja serwera — test połączenia. */
    public function version(DatabaseHost $host): string;

    public function createDatabase(DatabaseHost $host, string $database, string $username, string $password, string $remote): void;

    public function dropDatabase(DatabaseHost $host, string $database, string $username, string $remote): void;

    public function changePassword(DatabaseHost $host, string $username, string $remote, string $password): void;

    /** Rozmiar bazy w bajtach. */
    public function size(DatabaseHost $host, string $database): int;
}
