<?php

namespace Tests\Feature;

use App\Enums\ServerState;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Pulpit administracji w stylu paneli hostingowych, grupy w pasku bocznym i dziennik zdarzeń. */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_kafelki_wezly_zadania_i_ostatnie_uslugi(): void
    {
        $node = Hypervisor::factory()->create([
            'name' => 'node-waw-1', 'enrolled_at' => now(),
            'last_health' => ['cpu_cores_total' => 8, 'cpu_load_1m' => 2.0, 'ram_mb_total' => 1000, 'ram_mb_free' => 250, 'disk_gb_total' => 100, 'disk_gb_free' => 90],
        ]);
        Server::factory()->count(2)->create(['hypervisor_id' => $node->id, 'state' => ServerState::Running]);
        $broken = Server::factory()->create(['hypervisor_id' => $node->id, 'state' => ServerState::Error, 'hostname' => 'zepsuta.example']);
        ServerJob::create(['server_id' => $broken->id, 'hypervisor_id' => $node->id, 'action' => 'create', 'status' => ServerJob::STATUS_FAILED, 'error' => 'Brak miejsca na dysku']);

        $this->actingAs($this->admin)->get(route('panel.admin.index'))
            ->assertOk()
            ->assertSee('node-waw-1')
            ->assertSee('25%')   // load 2.0 / 8 rdzeni
            ->assertSee('75%')   // RAM hosta
            ->assertSee('Brak miejsca na dysku')
            ->assertSee('zepsuta.example')
            ->assertSee(route('panel.admin.services', ['status' => 'problem']), false);

        $this->actingAs($this->admin)->get(route('panel.admin.index', ['recent' => 10]))->assertOk();
    }

    public function test_aktywni_uzytkownicy_z_sesji(): void
    {
        $customer = User::factory()->create(['name' => 'Ola Klientka']);
        DB::table('sessions')->insert([
            'id' => 'sesja-1', 'user_id' => $customer->id, 'ip_address' => '198.51.100.7',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->subMinutes(2)->getTimestamp(),
        ]);
        config(['session.driver' => 'database']);

        $this->actingAs($this->admin)->get(route('panel.admin.index'))
            ->assertOk()->assertSee('Ola Klientka')->assertSee('198.51.100.7');
    }

    public function test_pasek_boczny_w_grupach(): void
    {
        $this->actingAs($this->admin)->get(route('panel.admin.templates'))
            ->assertOk()
            ->assertSee('Infrastruktura')->assertSee('Media')->assertSee('System')
            ->assertSee(route('panel.admin.logs'), false);

        // Support nie widzi działów bez uprawnień ani dziennika.
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $this->actingAs($support)->get(route('panel.admin.index'))
            ->assertOk()
            ->assertDontSee(route('panel.admin.logs'), false)
            ->assertDontSee(route('panel.admin.updates'), false);
    }

    public function test_dziennik_zdarzen_filtruje_i_jest_tylko_dla_administratora(): void
    {
        AuditLog::record('server.ordered', null, [], $this->admin);
        AuditLog::record('user.created', null, [], $this->admin);

        $this->actingAs($this->admin)->get(route('panel.admin.logs', ['category' => 'server']))
            ->assertOk()->assertSee('server.ordered')->assertDontSee('user.created');
        $this->actingAs($this->admin)->get(route('panel.admin.logs', ['q' => 'user.']))
            ->assertOk()->assertSee('user.created');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPPORT]))
            ->get(route('panel.admin.logs'))->assertForbidden();
    }
}
