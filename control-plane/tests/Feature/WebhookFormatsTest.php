<?php

namespace Tests\Feature;

use App\Domain\Webhooks\Webhooks;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookFormatsTest extends TestCase
{
    use RefreshDatabase;

    private function hook(array $attributes): Webhook
    {
        $webhook = Webhook::create($attributes + ['name' => 'Test', 'url' => 'https://hooks.example.test/in', 'secret' => 'whsec_test', 'events' => ['*'], 'is_active' => true]);
        app(Webhooks::class)->forget();

        return $webhook;
    }

    public function test_token_bearer_jest_wysylany_i_szyfrowany(): void
    {
        Http::swap(new Factory);
        Http::fake(['hooks.example.test/*' => Http::response('ok')]);
        $webhook = $this->hook(['bearer_token' => 'tok_abc123']);

        app(Webhooks::class)->dispatch('server.built', ['hostname' => 'vps1.test']);

        Http::assertSent(fn (Request $r) => $r->header('Authorization')[0] === 'Bearer tok_abc123' && $r['event'] === 'server.built');
        $this->assertStringNotContainsString('tok_abc123', (string) $webhook->getRawOriginal('bearer_token'));
        $this->assertArrayNotHasKey('bearer_token', $webhook->toArray());
    }

    public function test_bez_tokenu_nie_ma_naglowka_authorization(): void
    {
        Http::swap(new Factory);
        Http::fake(['hooks.example.test/*' => Http::response('ok')]);
        $this->hook([]);

        app(Webhooks::class)->dispatch('server.built', ['hostname' => 'vps1.test']);

        Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));
    }

    public function test_odbiorniki_dostaja_czytelna_wiadomosc(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('ok')]);
        $this->hook(['url' => 'https://hooks.slack.test/s', 'format' => 'slack']);
        $this->hook(['url' => 'https://discord.test/api/webhooks/1', 'format' => 'discord']);
        $this->hook(['url' => 'https://chat.googleapis.test/v1', 'format' => 'google_chat']);
        $this->hook(['url' => 'https://api.telegram.test/botX/sendMessage', 'format' => 'telegram', 'options' => ['chat_id' => '-100123']]);
        $this->hook(['url' => 'https://api.pushover.test/1/messages.json', 'format' => 'pushover', 'options' => ['user_key' => 'uKEY', 'app_token' => 'aTOK']]);

        $this->assertSame(5, app(Webhooks::class)->dispatch('server.built', ['server' => ['hostname' => 'vps1.example.com', 'primary_ip' => '203.0.113.10']]));

        $has = fn (string $value) => str_contains($value, 'vps1.example.com') && str_contains($value, '203.0.113.10');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'slack') && $has($r['text']) && ! isset($r['event']));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'discord') && $has($r['content']) && $r['allowed_mentions'] === ['parse' => []]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'googleapis') && $has($r['text']));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'telegram') && $r['chat_id'] === '-100123' && $has($r['text']));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'pushover') && $r['token'] === 'aTOK' && $r['user'] === 'uKEY' && $has($r['message']));
    }

    public function test_formularz_waliduje_opcje_odbiornika_i_zachowuje_sekrety(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $base = ['name' => 'Kanał', 'url' => 'https://api.telegram.test/botX/sendMessage', 'events' => ['*']];

        $this->actingAs($admin)->post(route('panel.admin.webhooks.store'), $base + ['format' => 'telegram'])->assertSessionHasErrors('options.chat_id');
        $this->post(route('panel.admin.webhooks.store'), $base + ['format' => 'telegram', 'options' => ['chat_id' => 'nie ma']])->assertSessionHasErrors('options.chat_id');
        $this->post(route('panel.admin.webhooks.store'), $base + ['format' => 'nieznany'])->assertSessionHasErrors('format');
        $this->post(route('panel.admin.webhooks.store'), $base + ['format' => 'pushover', 'options' => ['user_key' => 'u1']])->assertSessionHasErrors('options.app_token');
        $this->post(route('panel.admin.webhooks.store'), $base + ['format' => 'virthub', 'bearer_token' => 'z spacja'])->assertSessionHasErrors('bearer_token');

        $this->post(route('panel.admin.webhooks.store'), $base + ['format' => 'pushover', 'bearer_token' => 'tok1', 'options' => ['user_key' => 'u1', 'app_token' => 'a1']])->assertSessionHasNoErrors();
        $webhook = Webhook::firstOrFail();
        $this->assertSame(['user_key' => 'u1', 'app_token' => 'a1'], $webhook->options);
        $this->assertSame('tok1', $webhook->bearer_token);

        // Puste pola sekretów przy edycji = bez zmiany.
        $this->put(route('panel.admin.webhooks.update', $webhook), $base + ['format' => 'pushover', 'options' => ['user_key' => 'u2']])->assertSessionHasNoErrors();
        $webhook->refresh();
        $this->assertSame(['user_key' => 'u2', 'app_token' => 'a1'], $webhook->options);
        $this->assertSame('tok1', $webhook->bearer_token);

        $this->put(route('panel.admin.webhooks.update', $webhook), $base + ['format' => 'virthub', 'clear_bearer' => '1'])->assertSessionHasNoErrors();
        $webhook->refresh();
        $this->assertNull($webhook->bearer_token);
        $this->assertNull($webhook->options);
        $this->assertSame('virthub', $webhook->format);
        $this->get(route('panel.admin.webhooks.show', $webhook))->assertOk()->assertSee('Pushover');
    }
}
