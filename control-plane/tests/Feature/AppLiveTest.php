<?php

namespace Tests\Feature;

use App\Domain\Apps\AppProvisioner;
use App\Domain\Apps\EggImporter;
use App\Models\AppEgg;
use App\Models\AppPlan;
use App\Models\AppServer;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Aplikacje na żywo: konsola przez WebSocket (sesje przekaźnika) i logowanie SFTP. */
class AppLiveTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'wspolny-sekret-przekaznika';

    private Hypervisor $node;

    private User $customer;

    private AppServer $appServer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['virthub.console_secret' => self::SECRET]);

        $this->node = Hypervisor::factory()->create([
            'agent_url' => 'https://203.0.113.10:8443',
            'apps_enabled' => true,
            'app_port_start' => 25565,
            'app_port_end' => 25574,
            'ram_mb_total' => 16384,
            'disk_gb_total' => 500,
            'last_health' => ['apps' => ['available' => true], 'public_ipv4' => '203.0.113.10'],
        ]);
        $this->customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        app(EggImporter::class)->importBuiltin();
        $plan = AppPlan::query()->create(['name' => 'S', 'memory_mb' => 1024, 'cpu_percent' => 100, 'disk_mb' => 2048, 'ports' => 1]);
        $this->appServer = app(AppProvisioner::class)->order(
            $this->customer, AppEgg::query()->where('builtin_key', 'minecraft-paper')->firstOrFail(), $plan, 'Survival',
        );
        $this->appServer->update(['status' => AppServer::STATUS_READY]);
    }

    // --- konsola na żywo ------------------------------------------------------------

    public function test_sesja_konsoli_aplikacji_prowadzi_do_websocketu_agenta(): void
    {
        $response = $this->actingAs($this->customer)->postJson(route('panel.apps.console', $this->appServer))->assertOk();
        $path = $response->json('path');
        $this->assertMatchesRegularExpression('#^/console-ws/[A-Za-z0-9]{64}$#', $path);
        $session = substr($path, strlen('/console-ws/'));

        $params = $this->withHeader('X-Console-Secret', self::SECRET)
            ->postJson("/api/internal/console/{$session}")
            ->assertOk()
            ->json();

        $agentPath = "/apps/{$this->appServer->uuid}/console";
        $this->assertSame('wss://203.0.113.10:8443'.$agentPath, $params['url']);
        $this->assertSame('app', $params['kind']);
        $this->assertSame($this->appServer->id, $params['app_id']);
        $expected = hash_hmac('sha256', implode("\n", [$params['headers']['X-VH-Timestamp'], 'GET', $agentPath, hash('sha256', '')]), $this->node->agent_token);
        $this->assertSame($expected, $params['headers']['X-VH-Signature']);

        // Sesja jest jednorazowa.
        $this->withHeader('X-Console-Secret', self::SECRET)->postJson("/api/internal/console/{$session}")->assertStatus(410);
    }

    public function test_obcy_nie_dostanie_sesji_konsoli(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->postJson(route('panel.apps.console', $this->appServer))
            ->assertForbidden();
    }

    public function test_zawieszona_aplikacja_nie_ma_konsoli(): void
    {
        $this->appServer->update(['suspended_at' => now(), 'suspension_reason' => 'test']);

        $this->actingAs($this->customer)->postJson(route('panel.apps.console', $this->appServer))->assertStatus(409);
    }

    public function test_bez_przekaznika_konsola_przechodzi_na_odpytywanie(): void
    {
        config(['virthub.console_secret' => null]);

        $this->actingAs($this->customer)->postJson(route('panel.apps.console', $this->appServer))
            ->assertStatus(409)
            ->assertJson(['enabled' => false]);

        $this->actingAs($this->customer)->get(route('panel.apps.show', $this->appServer))
            ->assertOk()
            ->assertSee('session: null', false);
    }

    public function test_strona_konsoli_laduje_xterm_i_adres_sesji(): void
    {
        $this->actingAs($this->customer)->get(route('panel.apps.show', $this->appServer))
            ->assertOk()
            ->assertSee('vendor/xterm/xterm.js', false)
            ->assertSee(str_replace('/', '\\/', route('panel.apps.console', $this->appServer)), false);
    }

    // --- SFTP -------------------------------------------------------------------------

    private function sftpAuth(array $body, ?string $secret = null): TestResponse
    {
        $json = json_encode($body);
        $ts = (string) time();
        $sig = hash_hmac('sha256', implode("\n", [$ts, 'POST', '/api/internal/agent/sftp-auth', hash('sha256', $json)]), $secret ?? $this->node->callback_secret);

        return $this->call('POST', '/api/internal/agent/sftp-auth', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_VH_TIMESTAMP' => $ts,
            'HTTP_X_VH_SIGNATURE' => $sig,
        ], $json);
    }

    public function test_sftp_przyjmuje_haslo_wlasciciela(): void
    {
        $this->sftpAuth(['uuid' => $this->appServer->uuid, 'user_id' => $this->customer->id, 'password' => 'password'])
            ->assertOk()
            ->assertJson(['allowed' => true]);
    }

    public function test_sftp_odrzuca_zle_haslo_obcego_i_cudzy_podpis(): void
    {
        $this->sftpAuth(['uuid' => $this->appServer->uuid, 'user_id' => $this->customer->id, 'password' => 'zle'])
            ->assertForbidden()->assertJson(['allowed' => false]);

        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->sftpAuth(['uuid' => $this->appServer->uuid, 'user_id' => $stranger->id, 'password' => 'password'])->assertForbidden();

        // Inny węzeł (inny sekret) nie zapyta o aplikację z tego węzła.
        $other = Hypervisor::factory()->create();
        $this->sftpAuth(['uuid' => $this->appServer->uuid, 'user_id' => $this->customer->id, 'password' => 'password'], $other->callback_secret)
            ->assertStatus(401);
    }

    public function test_sftp_odrzuca_zawieszonych(): void
    {
        $this->customer->forceFill(['suspended_at' => now()])->save();
        $this->sftpAuth(['uuid' => $this->appServer->uuid, 'user_id' => $this->customer->id, 'password' => 'password'])->assertForbidden();

        $this->customer->forceFill(['suspended_at' => null])->save();
        $this->appServer->update(['suspended_at' => now(), 'suspension_reason' => 'test']);
        $this->sftpAuth(['uuid' => $this->appServer->uuid, 'user_id' => $this->customer->id, 'password' => 'password'])->assertForbidden();
    }

    public function test_sftp_limituje_zgadywanie_hasla(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->sftpAuth(['uuid' => $this->appServer->uuid, 'user_id' => $this->customer->id, 'password' => "zle{$i}"])->assertForbidden();
        }

        $this->sftpAuth(['uuid' => $this->appServer->uuid, 'user_id' => $this->customer->id, 'password' => 'password'])->assertStatus(429);
    }

    public function test_ustawienia_pokazuja_dane_sftp(): void
    {
        $user = 'u'.$this->customer->id.'.'.substr($this->appServer->uuid, 0, 8);

        $this->actingAs($this->customer)->get(route('panel.apps.settings', $this->appServer))
            ->assertOk()
            ->assertSee($user)
            ->assertSee('203.0.113.10')
            ->assertSee('sftp://'.$user.'@203.0.113.10:2022', false);
    }
}
