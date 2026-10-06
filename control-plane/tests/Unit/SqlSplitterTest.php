<?php

namespace Tests\Unit;

use App\Domain\Apps\Databases\SqlSplitter;
use PHPUnit\Framework\TestCase;

class SqlSplitterTest extends TestCase
{
    public function test_dzieli_na_zapytania_z_pominieciem_srednikow_w_tekstach_i_komentarzy(): void
    {
        $sql = <<<'SQL'
            -- komentarz; z średnikiem
            CREATE TABLE `a;b` (id INT); # drugi; komentarz
            INSERT INTO t VALUES ('x;y', "it\"s;", 'O''Reilly;');
            /* blok; komentarza */ SELECT 1;
            /*!40101 SET NAMES utf8mb4 */;
            SELECT 2
            SQL;

        $this->assertSame([
            'CREATE TABLE `a;b` (id INT)',
            "INSERT INTO t VALUES ('x;y', \"it\\\"s;\", 'O''Reilly;')",
            'SELECT 1',
            '/*!40101 SET NAMES utf8mb4 */',
            'SELECT 2',
        ], SqlSplitter::split($sql));
    }

    public function test_puste_wejscie(): void
    {
        $this->assertSame([], SqlSplitter::split(" ;\n-- tylko komentarz\n;"));
    }
}
