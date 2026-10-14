<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\ServerProvisioner;
use App\Jobs\RunServerActionJob;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ElevateTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->server = Server::factory()->create();
    }

    private function finish(ServerJob $job, bool $elevated): void
    {
        app(AgentResultApplier::class)->apply($job->fresh(), ['status' => 'done', 'result' => ['elevated' => $elevated, 'state' => 'running']]);
    }

    public function test_pelny_cykl(): void
    {
        $this->actingAs($this->server->user)->get(route('panel.servers.show', $this->server))->assertOk()->assertSee(__('Włącz Elevate'));

        $this->post(route('panel.servers.elevate', $this->server), ['action' => 'commit'])->assertSessionHasErrors('elevate');
        $this->post(route('panel.servers.elevate', $this->server), ['action' => 'start'])->assertSessionHasNoErrors();
        $job = ServerJob::where('action', 'elevate')->latest('id')->firstOrFail();
        $this->assertSame(['action' => 'start'], $job->payload);

        Http::fake(['*' => Http::response(['job_id' => 'a1', 'status' => 'queued'], 202)]);
        (new RunServerActionJob($job->id))->handle();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/vm/{$this->server->agent_uuid}/elevate") && $r['action'] === 'start');

        $this->finish($job, true);
        $this->assertTrue($this->server->fresh()->isElevated());
        $this->get(route('panel.servers.show', $this->server))->assertSee(__('Zatwierdź zmiany'));

        // W trakcie Elevate migawka jest zablokowana.
        $this->expectsDomainError(fn () => app(ServerProvisioner::class)->snapshot($this->server->fresh(), 'x'));

        $this->post(route('panel.servers.elevate', $this->server), ['action' => 'discard'])->assertSessionHasNoErrors();
        $this->finish(ServerJob::where('action', 'elevate')->latest('id')->firstOrFail(), false);
        $this->assertFalse($this->server->fresh()->isElevated());
    }

    public function test_kontener_i_obcy_uzytkownik(): void
    {
        $container = Server::factory()->create(['virtualization' => 'lxc']);
        $this->actingAs($container->user)->post(route('panel.servers.elevate', $container), ['action' => 'start'])->assertSessionHasErrors('elevate');
        $this->assertSame(0, ServerJob::where('action', 'elevate')->count());

        $this->actingAs(User::factory()->create())->post(route('panel.servers.elevate', $this->server), ['action' => 'start'])->assertForbidden();
        $this->actingAs($this->server->user)->post(route('panel.servers.elevate', $this->server), ['action' => 'zly'])->assertSessionHasErrors('action');
    }

    private function expectsDomainError(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Oczekiwano DomainException');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Elevate', $e->getMessage());
        }
    }
}
