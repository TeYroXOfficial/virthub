<?php

namespace Tests\Feature;

use App\Domain\Apps\Databases\DatabaseBrowser;
use App\Domain\Apps\Databases\DatabaseException;
use App\Domain\Apps\Databases\DatabaseManager;
use App\Domain\Apps\Databases\MysqlDatabaseServer;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\DatabaseHost;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Prawdziwy MySQL/MariaDB (VIRTHUB_TEST_MYSQL_HOST/_PORT/_USER/_PASSWORD).
 * Pomijany, gdy serwer nie jest dostępny.
 */
class AppDatabasesMysqlTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseHost $host;

    private DatabaseManager $manager;

    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Brak rozszerzenia pdo_mysql.');
        }
        $host = new DatabaseHost([
            'name' => 'test', 'host' => getenv('VIRTHUB_TEST_MYSQL_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('VIRTHUB_TEST_MYSQL_PORT') ?: 3306),
            'username' => getenv('VIRTHUB_TEST_MYSQL_USER') ?: 'vhadmin', 'is_active' => true,
        ]);
        $host->setSecret(getenv('VIRTHUB_TEST_MYSQL_PASSWORD') ?: 'vhadmin-pass');
        try {
            app(MysqlDatabaseServer::class)->version($host);
        } catch (DatabaseException $e) {
            $this->markTestSkipped('MySQL niedostępny: '.$e->getMessage());
        }
        $host->save();
        $this->host = $host;
        $this->manager = app(DatabaseManager::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $db) {
            try {
                $this->manager->delete($db);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    private function makeApp(): AppServer
    {
        static $plan;
        $node = Hypervisor::factory()->create();
        $plan = AppPlan::query()->firstOrCreate(['name' => 'DB'], ['memory_mb' => 1024, 'disk_mb' => 1024, 'ports' => 1, 'databases' => 2]);
        $app = new AppServer(['name' => 'Test', 'memory_mb' => 1024, 'disk_mb' => 1024, 'docker_image' => 'x', 'startup' => 'x']);
        $app->forceFill(['user_id' => User::factory()->create()->id, 'hypervisor_id' => $node->id, 'app_plan_id' => $plan->id,
            'app_egg_id' => AppEgg::query()->create(['name' => 'Egg', 'docker_images' => ['x' => 'x'], 'startup' => 'x'])->id,
            'status' => AppServer::STATUS_READY, 'database_limit' => 5])->save();

        return $app;
    }

    public function test_pelny_cykl_bazy_na_prawdziwym_serwerze(): void
    {
        $app = $this->makeApp();
        $db = $this->created[] = $this->manager->create($app, 'main_'.random_int(1000, 9999));
        $other = $this->created[] = $this->manager->create($this->makeApp(), 'other_'.random_int(1000, 9999));

        $browser = new DatabaseBrowser($db);
        $result = $browser->run("CREATE TABLE players (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32), note TEXT NULL);
            INSERT INTO players (name, note) VALUES ('Steve', 'a;b'), ('Alex', NULL);");
        $this->assertSame(2, $result['affected']);

        $select = $browser->run('SELECT name, note FROM players ORDER BY id');
        $this->assertSame(['name', 'note'], $select['columns']);
        $this->assertSame([['name' => 'Steve', 'note' => 'a;b'], ['name' => 'Alex', 'note' => null]], $select['rows']);

        $this->assertSame(['players'], array_column($browser->tables(), 'name'));
        $this->assertSame(['id', 'name', 'note'], array_column($browser->columns('players'), 'Field'));
        $page = $browser->rows('players', 1, 1, 'name', 'asc');
        $this->assertSame(2, $page['total']);
        $this->assertSame('Alex', $page['rows'][0]['name']);

        // Izolacja: konto klienta nie widzi cudzej bazy.
        try {
            $browser->run("SELECT * FROM `{$other->database}`.players");
            $this->fail('Dostęp do cudzej bazy');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('denied', strtolower($e->getMessage()));
        }

        // Eksport → import do drugiej bazy daje te same dane.
        $dump = '';
        $browser->export(function ($chunk) use (&$dump) {
            $dump .= $chunk;
        });
        $this->assertStringContainsString('CREATE TABLE `players`', $dump);
        $this->assertGreaterThan(0, (new DatabaseBrowser($other))->import($dump."\nUSE mysql;\n"));
        $this->assertSame([['c' => 2]], (new DatabaseBrowser($other))->run('SELECT COUNT(*) AS c FROM players')['rows']);

        // Nowe hasło działa, stare nie.
        $old = $db->secret();
        $this->manager->rotatePassword($db);
        $this->assertNotSame($old, $db->secret());
        (new DatabaseBrowser($db->fresh()))->tables();
        try {
            MysqlDatabaseServer::connect($this->host->host, $this->host->port, $db->username, $old, $db->database);
            $this->fail('Stare hasło nadal działa');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->assertGreaterThan(0, $this->manager->size($db));

        // Usunięcie usuwa bazę i użytkownika.
        $this->manager->delete($db);
        $this->created = [$other];
        $this->expectException(DatabaseException::class);
        MysqlDatabaseServer::connect($this->host->host, $this->host->port, $db->username, $db->secret());
    }

    public function test_przegladarka_w_panelu(): void
    {
        $app = $this->makeApp();
        $db = $this->created[] = $this->manager->create($app, 'web_'.random_int(1000, 9999));
        $owner = $app->user;

        $this->actingAs($owner)->post(route('panel.apps.databases.query', [$app, $db]), [
            'sql' => "CREATE TABLE items (id INT PRIMARY KEY, label VARCHAR(20)); INSERT INTO items VALUES (1, '<b>x</b>'), (2, NULL); SELECT * FROM items",
        ])->assertRedirect(route('panel.apps.databases.browse', [$app, $db]))->assertSessionHas('result');
        $this->actingAs($owner)->get(route('panel.apps.databases.browse', [$app, $db]))->assertOk()
            ->assertSee('items')->assertSee('&lt;b&gt;x&lt;/b&gt;', false)->assertDontSee('<b>x</b>', false);

        $this->actingAs($owner)->get(route('panel.apps.databases.table', [$app, $db, 'items', 'sort' => 'id', 'dir' => 'desc']))->assertOk()
            ->assertSee('varchar(20)', false)->assertSee('▼');
        $this->actingAs($owner)->get(route('panel.apps.databases.table', [$app, $db, 'nope']))->assertRedirect();

        $this->actingAs($owner)->post(route('panel.apps.databases.query', [$app, $db]), ['sql' => 'SELECT * FROM missing'])->assertSessionHasErrors('sql');

        $dump = $this->actingAs($owner)->get(route('panel.apps.databases.export', [$app, $db]))->assertOk()->streamedContent();
        $this->assertStringContainsString('INSERT INTO `items`', $dump);

        $file = UploadedFile::fake()->createWithContent('dump.sql', "CREATE TABLE extra (id INT);\nINSERT INTO extra VALUES (1);");
        $this->actingAs($owner)->post(route('panel.apps.databases.import', [$app, $db]), ['file' => $file])->assertSessionHasNoErrors();
        $this->assertContains('extra', array_column((new DatabaseBrowser($db))->tables(), 'name'));

        // Inny klient nie ma dostępu.
        $this->actingAs(User::factory()->create())->get(route('panel.apps.databases.browse', [$app, $db]))->assertForbidden();
    }
}
