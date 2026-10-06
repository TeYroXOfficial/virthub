<?php

namespace Tests\Feature;

use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\Databases\DatabaseException;
use App\Domain\Apps\Databases\DatabaseManager;
use App\Domain\Apps\Databases\DatabaseServer;
use App\Domain\Apps\EggImporter;
use App\Models\AppDatabase;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\DatabaseHost;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Bazy danych aplikacji na atrapie serwera MySQL: limity, uprawnienia, serwery baz. */
class AppDatabasesTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private AppServer $gameApp;

    private FakeDatabaseServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true])]);
        $this->server = new FakeDatabaseServer;
        $this->app->instance(DatabaseServer::class, $this->server);

        $node = Hypervisor::factory()->create([
            'apps_enabled' => true, 'app_port_start' => 25565, 'app_port_end' => 25574, 'ram_mb_total' => 16384, 'ram_mb_used' => 0,
            'disk_gb_total' => 500, 'disk_gb_used' => 0, 'last_health' => ['apps' => ['available' => true, 'docker_version' => '29.0'], 'public_ipv4' => '203.0.113.10'],
        ]);
        $plan = AppPlan::query()->create(['name' => 'Gra S', 'memory_mb' => 2048, 'disk_mb' => 10240, 'ports' => 1, 'databases' => 1]);
        app(EggImporter::class)->importBuiltin();
        $this->customer = User::factory()->create();
        $this->gameApp = app(AppProvisioner::class)->order($this->customer, AppEgg::query()->where('builtin_key', 'minecraft-paper')->firstOrFail(), $plan, 'Survival');
        $this->gameApp->forceFill(['status' => AppServer::STATUS_READY])->save();
    }

    private function host(array $attrs = []): DatabaseHost
    {
        $host = new DatabaseHost($attrs + ['name' => 'MariaDB 1', 'host' => '10.0.0.5', 'port' => 3306, 'public_host' => 'db.example.com', 'username' => 'virthub', 'is_active' => true]);
        $host->setSecret('admin-secret');
        $host->save();

        return $host;
    }

    public function test_klient_tworzy_baze_w_limicie_planu_zmienia_haslo_i_usuwa(): void
    {
        $this->host();
        $this->actingAs($this->customer)->get(route('panel.apps.databases', $this->gameApp))->assertOk()->assertSee('0 z 1');

        $this->actingAs($this->customer)->post(route('panel.apps.databases.store', $this->gameApp), ['name' => 'luckperms'])->assertSessionHasNoErrors();
        $db = AppDatabase::query()->sole();
        $this->assertSame('s'.$this->gameApp->id.'_luckperms', $db->database);
        $this->assertStringStartsWith('u'.$this->gameApp->id.'_', $db->username);
        $this->assertSame($db->secret(), $this->server->users[$db->username]);
        $this->assertNotSame($db->secret(), $db->getRawOriginal('password'), 'hasło szyfrowane w bazie panelu');

        $this->actingAs($this->customer)->get(route('panel.apps.databases', $this->gameApp))->assertOk()
            ->assertSee('db.example.com:3306')->assertSee('jdbc:mysql://db.example.com:3306/'.$db->database);

        // Limit planu: 1 baza.
        $this->actingAs($this->customer)->post(route('panel.apps.databases.store', $this->gameApp), ['name' => 'drugi'])->assertSessionHasErrors('database');
        // Zła nazwa.
        $this->actingAs($this->customer)->post(route('panel.apps.databases.store', $this->gameApp), ['name' => 'Zła-Nazwa'])->assertSessionHasErrors('name');

        $old = $db->secret();
        $this->actingAs($this->customer)->post(route('panel.apps.databases.password', [$this->gameApp, $db]))->assertSessionHas('status');
        $this->assertNotSame($old, $db->fresh()->secret());
        $this->assertSame($db->fresh()->secret(), $this->server->users[$db->username]);

        $this->actingAs($this->customer)->delete(route('panel.apps.databases.destroy', [$this->gameApp, $db]), ['confirm' => 'zla'])->assertSessionHasErrors('confirm');
        $this->actingAs($this->customer)->delete(route('panel.apps.databases.destroy', [$this->gameApp, $db]), ['confirm' => $db->database])->assertSessionHasNoErrors();
        $this->assertSame(0, AppDatabase::query()->count());
        $this->assertSame([], $this->server->databases);
    }

    public function test_cudza_baza_i_limit_niedostepne_dla_klienta(): void
    {
        $this->host();
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('panel.apps.databases', $this->gameApp))->assertForbidden();
        $this->actingAs($other)->post(route('panel.apps.databases.store', $this->gameApp), ['name' => 'x'])->assertForbidden();
        $this->actingAs($this->customer)->put(route('panel.apps.databases.limit', $this->gameApp), ['database_limit' => 10])->assertForbidden();

        // Baza innej aplikacji pod adresem tej aplikacji → 404.
        $db = app(DatabaseManager::class)->create($this->gameApp, 'main');
        $otherApp = AppServer::query()->create($this->gameApp->only(['hypervisor_id', 'app_egg_id', 'app_plan_id', 'memory_mb', 'cpu_percent', 'disk_mb', 'docker_image', 'startup']) + ['user_id' => $other->id, 'name' => 'Inny', 'status' => AppServer::STATUS_READY]);
        $this->actingAs($other)->post(route('panel.apps.databases.password', [$otherApp, $db]))->assertNotFound();
    }

    public function test_bez_serwera_baz_i_bez_limitu(): void
    {
        $this->actingAs($this->customer)->get(route('panel.apps.databases', $this->gameApp))->assertOk()->assertSee(__('Brak dostępnego serwera baz danych dla tej aplikacji. Skontaktuj się z obsługą.'));
        $this->actingAs($this->customer)->post(route('panel.apps.databases.store', $this->gameApp), ['name' => 'x'])->assertSessionHasErrors('database');

        $this->host();
        $this->gameApp->forceFill(['database_limit' => 0])->save();
        $this->actingAs($this->customer)->post(route('panel.apps.databases.store', $this->gameApp), ['name' => 'x'])->assertSessionHasErrors('database');
    }

    public function test_personel_limit_ponad_limit_i_wybor_serwera(): void
    {
        $this->host();
        $node = $this->host(['name' => 'Na węźle', 'hypervisor_id' => $this->gameApp->hypervisor_id]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Domyślnie wybierany serwer przypisany do węzła aplikacji.
        $this->actingAs($admin)->post(route('panel.apps.databases.store', $this->gameApp), ['name' => 'a'])->assertSessionHasNoErrors();
        $this->assertSame($node->id, AppDatabase::query()->sole()->database_host_id);
        // Personel ponad limit planu.
        $this->actingAs($admin)->post(route('panel.apps.databases.store', $this->gameApp), ['name' => 'b'])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->gameApp->databases()->count());

        $this->actingAs($admin)->put(route('panel.apps.databases.limit', $this->gameApp), ['database_limit' => 3])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->gameApp->fresh()->database_limit);
        $this->actingAs($this->customer)->post(route('panel.apps.databases.store', $this->gameApp), ['name' => 'c'])->assertSessionHasNoErrors();
    }

    public function test_usuniecie_aplikacji_usuwa_bazy_nawet_gdy_serwer_bazy_nie_odpowiada(): void
    {
        $this->host();
        $manager = app(DatabaseManager::class);
        $manager->create($this->gameApp, 'main');
        $this->server->failing = true;
        $manager->deleteAllFor($this->gameApp);
        $this->assertSame(0, AppDatabase::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'app.database_orphaned']);
    }

    public function test_administrator_zarzadza_serwerami_baz(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('panel.admin.apps.databases'))->assertOk();

        $this->actingAs($admin)->post(route('panel.admin.apps.database-hosts.store'), [
            'name' => 'MariaDB', 'host' => '10.0.0.9', 'port' => 3306, 'username' => 'root', 'password' => 'tajne', 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $host = DatabaseHost::query()->sole();
        $this->assertSame('tajne', $host->secret());
        $this->assertNotSame('tajne', $host->getRawOriginal('password'));

        // Edycja bez hasła zostawia stare.
        $this->actingAs($admin)->put(route('panel.admin.apps.database-hosts.update', $host), [
            'name' => 'MariaDB 2', 'host' => '10.0.0.9', 'port' => 3307, 'username' => 'root', 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame('tajne', $host->fresh()->secret());
        $this->assertSame(3307, $host->fresh()->port);

        $this->actingAs($admin)->post(route('panel.admin.apps.database-hosts.test', $host))->assertSessionHas('status');

        // Nieudane połączenie blokuje zapis.
        $this->server->failing = true;
        $this->actingAs($admin)->post(route('panel.admin.apps.database-hosts.store'), [
            'name' => 'Zły', 'host' => '10.0.0.10', 'port' => 3306, 'username' => 'root', 'password' => 'x',
        ])->assertSessionHasErrors('host');
        $this->assertSame(1, DatabaseHost::query()->count());
        $this->server->failing = false;

        // Serwera z bazami nie da się usunąć.
        app(DatabaseManager::class)->create($this->gameApp, 'main');
        $this->actingAs($admin)->delete(route('panel.admin.apps.database-hosts.destroy', $host))->assertSessionHasErrors('host');

        // Klient nie ma dostępu do administracji.
        $this->actingAs($this->customer)->get(route('panel.admin.apps.databases'))->assertForbidden();
    }
}

/** Atrapa serwera MySQL: trzyma bazy i hasła w pamięci. */
class FakeDatabaseServer implements DatabaseServer
{
    public array $databases = [];

    public array $users = [];

    public bool $failing = false;

    private function check(): void
    {
        if ($this->failing) {
            throw new DatabaseException('Connection refused');
        }
    }

    public function version(DatabaseHost $host): string
    {
        $this->check();

        return '10.11.0-MariaDB';
    }

    public function createDatabase(DatabaseHost $host, string $database, string $username, string $password, string $remote): void
    {
        $this->check();
        $this->databases[$database] = $username;
        $this->users[$username] = $password;
    }

    public function dropDatabase(DatabaseHost $host, string $database, string $username, string $remote): void
    {
        $this->check();
        unset($this->databases[$database], $this->users[$username]);
    }

    public function changePassword(DatabaseHost $host, string $username, string $remote, string $password): void
    {
        $this->check();
        $this->users[$username] = $password;
    }

    public function size(DatabaseHost $host, string $database): int
    {
        $this->check();

        return 16384;
    }
}
