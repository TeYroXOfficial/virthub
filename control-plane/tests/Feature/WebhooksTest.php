<?php

namespace Tests\Feature;

use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Webhooks\Webhooks;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private function webhook(array $events = ['*']): Webhook
    {
        $webhook = Webhook::create(['name' => 'Test', 'url' => 'https://hooks.example.test/in', 'secret' => 'whsec_test', 'events' => $events, 'is_active' => true]);
        app(Webhooks::class)->forget();

        return $webhook;
    }

    public function test_administrator_dodaje_webhook_i_dostaje_sekret(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('panel.admin.webhooks'))->assertOk()->assertSee('server.built');
        $this->post(route('panel.admin.webhooks.store'), ['name' => 'CRM', 'url' => 'ftp://x', 'events' => ['server.built']])
            ->assertSessionHasErrors('url');
        $this->post(route('panel.admin.webhooks.store'), ['name' => 'CRM', 'url' => 'https://crm.test/hook', 'events' => ['server.built', 'nie.istnieje']])
            ->assertSessionHasErrors('events.1');
        $this->post(route('panel.admin.webhooks.store'), ['name' => 'CRM', 'url' => 'https://crm.test/hook', 'events' => ['server.built', 'user.created']])
            ->assertSessionHas('webhook_secret');

        $webhook = Webhook::firstOrFail();
        $this->assertSame(['server.built', 'user.created'], $webhook->events);
        $this->assertStringStartsWith('whsec_', $webhook->secret);
        $this->get(route('panel.admin.webhooks.show', $webhook))->assertOk()->assertSee('crm.test/hook');

        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('panel.admin.webhooks'))->assertForbidden();
    }

    public function test_dostarczenie_jest_podpisane(): void
    {
        Http::swap(new Factory);
        Http::fake(['hooks.example.test/*' => Http::response('ok', 200)]);
        $webhook = $this->webhook();

        $this->assertSame(1, app(Webhooks::class)->dispatch('server.built', ['id' => 5]));

        Http::assertSent(function (Request $request) {
            [$t, $v1] = explode(',', $request->header('X-VirtHub-Signature')[0]);
            $ts = substr($t, 2);
            $expected = hash_hmac('sha256', $ts.'.'.$request->body(), 'whsec_test');

            return $request->header('X-VirtHub-Event')[0] === 'server.built'
                && hash_equals($expected, substr($v1, 3))
                && $request['data']['id'] === 5 && $request['event'] === 'server.built';
        });
        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame(WebhookDelivery::SUCCESS, $delivery->status);
        $this->assertSame(200, $delivery->response_code);
        $this->assertSame(0, $webhook->fresh()->consecutive_failures);
    }

    public function test_webhook_dostaje_tylko_wybrane_zdarzenia(): void
    {
        Http::swap(new Factory);
        Http::fake();
        $this->webhook(['user.created']);

        $this->assertSame(0, app(Webhooks::class)->dispatch('server.built', []));
        $this->assertSame(1, app(Webhooks::class)->dispatch('user.created', []));
    }

    public function test_nieudane_dostarczenie_jest_ponawiane(): void
    {
        Http::swap(new Factory);
        Http::fakeSequence('hooks.example.test/*')->push('błąd', 500)->push('ok', 200);
        $this->webhook();

        app(Webhooks::class)->dispatch('server.power', ['id' => 1]);
        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame(WebhookDelivery::PENDING, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('HTTP 500', $delivery->error);

        // Przed czasem — nic.
        $this->assertSame(0, app(Webhooks::class)->retryDue());
        $this->travel(61)->seconds();
        $this->assertSame(1, app(Webhooks::class)->retryDue());
        $delivery->refresh();
        $this->assertSame(WebhookDelivery::SUCCESS, $delivery->status);
        $this->assertSame(2, $delivery->attempts);
    }

    public function test_po_wyczerpaniu_prob_dostarczenie_nieudane(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 503)]);
        $this->webhook();

        app(Webhooks::class)->dispatch('server.power', []);
        for ($i = 0; $i < count(Webhooks::RETRIES); $i++) {
            $this->travel(Webhooks::RETRIES[$i] + 1)->seconds();
            app(Webhooks::class)->retryDue();
        }
        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
        $this->assertSame(count(Webhooks::RETRIES) + 1, $delivery->attempts);
    }

    public function test_zadanie_agenta_wysyla_server_built(): void
    {
        Http::swap(new Factory);
        Http::fake();
        $this->webhook(['server.built', 'server.build_failed']);
        $hypervisor = Hypervisor::factory()->create();
        $server = Server::factory()->building()->create(['hypervisor_id' => $hypervisor->id, 'hostname' => 'vm1.example.test']);
        $job = ServerJob::create(['server_id' => $server->id, 'hypervisor_id' => $hypervisor->id, 'action' => 'create', 'status' => ServerJob::STATUS_RUNNING, 'agent_job_id' => 'j1']);

        app(AgentResultApplier::class)->apply($job, ['status' => 'done', 'result' => ['uuid' => '11111111-2222-3333-4444-555555555555', 'state' => 'running']]);

        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame('server.built', $delivery->event);
        $this->assertSame('vm1.example.test', $delivery->payload['data']['hostname']);
        $this->assertSame('running', $delivery->payload['data']['state']);
    }

    public function test_zdarzenia_z_dziennika_audytu(): void
    {
        Http::swap(new Factory);
        Http::fake();
        $this->webhook(['user.suspended']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['email' => 'klient@example.test']);

        $this->actingAs($admin)->post(route('panel.admin.users.suspend', $user));

        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame('user.suspended', $delivery->event);
        $this->assertSame('klient@example.test', $delivery->payload['data']['email']);
        $this->assertTrue($delivery->payload['data']['suspended']);
        $this->assertSame($admin->email, $delivery->payload['actor']);
    }

    public function test_test_ping_z_panelu(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 204)]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $webhook = $this->webhook(['user.created']);

        $this->actingAs($admin)->post(route('panel.admin.webhooks.ping', $webhook))
            ->assertSessionHas('status', fn ($s) => str_contains($s, '204'));
        $this->assertSame('ping', WebhookDelivery::firstOrFail()->event);
    }

    public function test_wylaczenie_po_serii_bledow(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 500)]);
        $webhook = $this->webhook();
        $webhook->forceFill(['consecutive_failures' => Webhooks::DISABLE_AFTER - 1])->save();

        app(Webhooks::class)->dispatch('server.power', []);

        $this->assertFalse($webhook->fresh()->is_active);
        $this->assertSame(0, app(Webhooks::class)->dispatch('server.power', []));
    }
}
