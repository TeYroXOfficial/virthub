<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Enums\ServerState;
use App\Jobs\RunServerActionJob;
use App\Models\Hypervisor;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServerSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->owner = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->server = Server::factory()->create([
            'user_id' => $this->owner->id,
            'hypervisor_id' => Hypervisor::factory()->create()->id,
        ]);
    }

    public function test_strona_ma_zakladki_i_przycisk_reinstalacji(): void
    {
        OsTemplate::factory()->create(['name' => 'Debian 13']);

        $this->actingAs($this->owner)->get(route('panel.servers.show', $this->server))
            ->assertOk()
            ->assertSee('data-tab="settings"', false)
            ->assertSee('data-open-reinstall', false)
            ->assertSee('Debian 13')
            ->assertSee('Resetuj hasło');
    }

    public function test_reset_hasla_pokazuje_nowe_haslo_raz(): void
    {
        $this->actingAs($this->owner)->post(route('panel.servers.password', $this->server))
            ->assertSessionHasNoErrors();

        $job = ServerJob::where('action', 'password')->sole();
        $this->assertStringStartsWith('eyJ', $job->payload['password'], 'hasło w zadaniu jest zaszyfrowane');

        Http::fake(['*' => Http::response(['job_id' => 'agent-1', 'status' => 'queued'], 202)]);
        (new RunServerActionJob($job->id))->handle();

        $sent = null;
        Http::assertSent(function (Request $r) use (&$sent) {
            $sent = $r['password'] ?? null;

            return str_ends_with($r->url(), "/vm/{$this->server->agent_uuid}/password");
        });
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{12,}$/', $sent);

        // Dopóki agent nie potwierdzi, hasło nie jest pokazywane.
        $this->actingAs($this->owner)->get(route('panel.servers.show', $this->server))
            ->assertDontSee($sent)->assertSee('data-watch-job', false);

        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'done', 'result' => ['state' => 'running']]);
        $this->assertSame([], $job->fresh()->payload, 'po zastosowaniu hasło znika z zadania');

        $this->actingAs($this->owner)->get(route('panel.servers.show', $this->server))->assertSee($sent);
        $this->actingAs($this->owner)->get(route('panel.servers.show', $this->server))->assertDontSee($sent);
    }

    public function test_nieudany_reset_nie_zostawia_hasla(): void
    {
        $this->actingAs($this->owner)->post(route('panel.servers.password', $this->server));
        $job = ServerJob::where('action', 'password')->sole();

        app(AgentResultApplier::class)->apply($job, ['status' => 'failed', 'error' => 'brak qemu-guest-agent']);

        $this->assertSame([], $job->fresh()->payload);
        $this->assertNull($this->server->fresh()->root_password);
    }

    public function test_reset_hasla_wymaga_dzialajacej_maszyny_i_uprawnienia(): void
    {
        $this->server->markState(ServerState::Stopped);
        $this->actingAs($this->owner)->post(route('panel.servers.password', $this->server))
            ->assertSessionHasErrors('password');

        $this->owner->forceFill(['permissions' => ['servers.power']])->save();
        $this->server->markState(ServerState::Running);
        $this->actingAs($this->owner->fresh())->post(route('panel.servers.password', $this->server))
            ->assertForbidden();

        $this->assertDatabaseCount('server_jobs', 0);
    }

    public function test_reinstalacja_pokazuje_ekran_postepu_i_status(): void
    {
        $template = OsTemplate::factory()->create();
        $this->actingAs($this->owner)->post(route('panel.servers.rebuild', $this->server), [
            'template' => $template->id, 'confirm' => '1',
        ]);

        $page = $this->actingAs($this->owner)->get(route('panel.servers.show', $this->server))
            ->assertOk()
            ->assertSee('id="progress"', false)
            ->assertSee('Reinstalacja serwera')
            ->assertSee('Kopiowanie obrazu systemu')
            ->assertDontSee('data-tab="settings"', false);

        // Hasło widać w trakcie i po zakończeniu reinstalacji (nie znika po pierwszym podglądzie).
        $password = $this->server->fresh()->root_password;
        $this->assertNotNull($password);
        $page->assertSee($password);

        $this->actingAs($this->owner)->getJson(route('panel.servers.status', $this->server))
            ->assertOk()
            ->assertJson(['state' => 'rebuilding', 'transitioning' => true, 'job' => ['action' => 'rebuild', 'finished' => false]]);

        $job = ServerJob::where('action', 'rebuild')->sole();
        app(AgentResultApplier::class)->apply($job, ['status' => 'done', 'result' => ['state' => 'running']]);

        $this->actingAs($this->owner)->getJson(route('panel.servers.status', $this->server))
            ->assertJson(['state' => 'running', 'transitioning' => false, 'job' => ['finished' => true]]);
        $this->actingAs($this->owner)->get(route('panel.servers.show', $this->server))->assertSee($password);
    }

    public function test_status_cudzej_maszyny_jest_niedostepny(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->getJson(route('panel.servers.status', $this->server))
            ->assertForbidden();
    }
}
