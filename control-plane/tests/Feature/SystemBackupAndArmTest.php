<?php

namespace Tests\Feature;

use App\Domain\Provisioning\Migrations;
use App\Domain\Provisioning\NoCapacityException;
use App\Domain\Provisioning\ServerProvisioner;
use App\Domain\System\SystemBackup;
use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Kopia systemowa panelu i węzły ARM. */
class SystemBackupAndArmTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        File::deleteDirectory(SystemBackup::directory());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(SystemBackup::directory());
        parent::tearDown();
    }

    public function test_kopia_panelu_tworzenie_pobieranie_retencja(): void
    {
        $this->actingAs($this->admin)->post(route('panel.admin.system-backups.store'))->assertSessionHasNoErrors();
        $list = app(SystemBackup::class)->list();
        $this->assertCount(1, $list);
        $name = $list[0]['name'];
        $path = SystemBackup::directory().'/'.$name;
        $this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        $phar = new \PharData($path);
        $files = [];
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            $files[] = str_replace('phar://'.$path.'/', '', $file->getPathname());
        }
        $this->assertContains('manifest.json', $files);
        $this->assertContains('.env', $files);
        // W testach baza jest w otwartej transakcji — zamiast VACUUM INTO powstaje zrzut SQL.
        $this->assertContains('database/database.sql', $files);
        $this->assertStringContainsString('INSERT INTO "users"', file_get_contents('phar://'.$path.'/database/database.sql'));
        $this->assertFalse(collect($files)->contains(fn ($f) => str_contains($f, 'system-backups')));
        $manifest = json_decode(file_get_contents('phar://'.$path.'/manifest.json'), true);
        $this->assertSame('virthub-panel/1', $manifest['format']);

        $this->get(route('panel.admin.system-backups'))->assertOk()->assertSee($name);
        $this->get(route('panel.admin.system-backups.download', $name))->assertOk()->assertHeader('content-type', 'application/gzip');
        $this->get(route('panel.admin.system-backups.download', '../../.env'))->assertNotFound();

        // Retencja: najstarsze ponad limit znikają.
        Setting::put(['system_backup.keep' => '2']);
        foreach (['20200101-000000', '20200102-000000'] as $i => $stamp) {
            touch(SystemBackup::directory()."/virthub-panel-{$stamp}.tar.gz", 1577836800 + $i);
        }
        $this->assertSame(1, app(SystemBackup::class)->prune());
        $this->assertCount(2, app(SystemBackup::class)->list());

        $this->put(route('panel.admin.system-backups.settings'), ['daily' => 1, 'keep' => 5])->assertSessionHasNoErrors();
        $this->assertTrue(SystemBackup::dailyEnabled());
        $this->artisan('virthub:system-backup --if-scheduled')->assertSuccessful();
        $this->assertCount(3, app(SystemBackup::class)->list());

        $this->delete(route('panel.admin.system-backups.destroy', $name))->assertRedirect();
        $this->assertNull(app(SystemBackup::class)->path($name));
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPPORT]))->get(route('panel.admin.system-backups'))->assertForbidden();
    }

    public function test_wezly_arm_i_szablony(): void
    {
        $x86 = Hypervisor::factory()->create(['name' => 'x86', 'virtualization' => 'kvm', 'cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 1000]);
        $arm = Hypervisor::factory()->create(['name' => 'arm', 'virtualization' => 'kvm', 'cpu_cores_total' => 32, 'ram_mb_total' => 65536, 'disk_gb_total' => 1000,
            'last_health' => ['arch' => 'aarch64']]);
        foreach ([$x86, $arm] as $node) {
            $pool = IpPool::factory()->create(['hypervisor_id' => $node->id]);
            IpAddress::factory()->count(2)->create(['ip_pool_id' => $pool->id, 'hypervisor_id' => $node->id]);
        }
        $package = VpsPackage::factory()->create(['ip_count' => 1]);
        $armTemplate = OsTemplate::factory()->create(['arch' => 'aarch64']);
        $x86Template = OsTemplate::factory()->create();

        $provisioner = app(ServerProvisioner::class);
        $this->assertSame('arm', $provisioner->order($this->admin, $package, $armTemplate, 'arm1.example.com')->hypervisor->name);
        $this->assertSame('x86', $provisioner->order($this->admin, $package, $x86Template, 'x861.example.com')->hypervisor->name);
        try {
            $provisioner->order($this->admin, $package, $armTemplate, 'arm2.example.com', preferred: $x86);
            $this->fail('Szablon ARM na węźle x86 powinien być odrzucony');
        } catch (NoCapacityException $e) {
            $this->assertStringContainsString('aarch64', $e->getMessage());
        }

        $server = Server::where('hostname', 'arm1.example.com')->firstOrFail()->forceFill(['agent_uuid' => '11111111-1111-1111-1111-111111111111']);
        $server->save();
        $server->markState(ServerState::Running);
        $reason = app(Migrations::class)->candidates($server->fresh())->firstWhere(fn ($c) => $c['node']->is($x86))['cold'];
        $this->assertStringContainsString('architekturę', (string) $reason);

        $this->actingAs($this->admin)->put(route('panel.admin.templates.update', $x86Template), [
            'name' => $x86Template->name, 'version' => $x86Template->version, 'min_disk_gb' => 10, 'arch' => 'aarch64',
        ])->assertSessionHasNoErrors();
        $this->assertSame('aarch64', $x86Template->fresh()->arch);
    }
}
