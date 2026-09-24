<?php

namespace Tests\Feature;

use App\Domain\Updates\Updates;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    private const LATEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private User $admin;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->dir = sys_get_temp_dir().'/vh-updates-'.uniqid();
        mkdir($this->dir);
        touch($this->dir.'/unit.path');

        config([
            'virthub.update_dir' => $this->dir,
            'virthub.update_unit' => $this->dir.'/unit.path',
            'virthub.update_repo' => 'owner/repo',
        ]);

        Http::fake([
            'api.github.com/*' => Http::response([
                'sha' => self::LATEST,
                'html_url' => 'https://github.com/owner/repo/commit/'.self::LATEST,
                'commit' => ['message' => "Nowa wersja\n\nopis", 'committer' => ['date' => '2026-09-24T10:00:00Z']],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function node(?string $build, array $overrides = []): Hypervisor
    {
        return Hypervisor::factory()->create([
            'enrolled_at' => now(),
            'last_health' => ['build' => $build, 'remote_update' => true],
            ...$overrides,
        ]);
    }

    public function test_najnowsza_wersja_z_githuba(): void
    {
        $latest = app(Updates::class)->latest();

        $this->assertSame(self::LATEST, $latest['sha']);
        $this->assertSame('Nowa wersja', $latest['message'], 'Tylko pierwsza linia opisu commita');
    }

    public function test_wersja_panelu_z_repozytorium_git(): void
    {
        $git = $this->dir.'/git';
        mkdir($git);
        file_put_contents("{$git}/HEAD", "ref: refs/heads/main\n");
        file_put_contents("{$git}/packed-refs", "# pack-refs\n".self::LATEST." refs/heads/main\n");

        $read = (new \ReflectionMethod(Updates::class, 'gitHead'))->invoke(app(Updates::class), $git);

        $this->assertSame(self::LATEST, $read, 'Gałąź tylko w packed-refs też musi się odczytać');
        unlink("{$git}/HEAD");
        unlink("{$git}/packed-refs");
        rmdir($git);
    }

    public function test_zlecenie_aktualizacji_panelu(): void
    {
        $this->actingAs($this->admin)
            ->post(route('panel.admin.updates.panel'))
            ->assertSessionHasNoErrors();

        $this->assertFileExists($this->dir.'/request');
        $this->assertSame('queued', app(Updates::class)->panelStatus()['state']);

        // Drugie zlecenie w trakcie jest odrzucane.
        $this->actingAs($this->admin)
            ->post(route('panel.admin.updates.panel'))
            ->assertSessionHasErrors('update');
    }

    public function test_nieodebrane_zlecenie_jest_zawieszone_i_mozna_je_ponowic(): void
    {
        file_put_contents($this->dir.'/request', '{}');
        touch($this->dir.'/request', time() - Updates::STALL_AFTER - 5);

        $status = app(Updates::class)->panelStatus();
        $this->assertSame('stalled', $status['state']);
        $this->assertStringContainsString('update-panel.sh', $status['message']);

        $this->actingAs($this->admin)->get(route('panel.admin.updates'))->assertOk()->assertSee('update-panel.sh');

        // Zawieszone zlecenie nie blokuje ponownej próby.
        $this->actingAs($this->admin)->post(route('panel.admin.updates.panel'))->assertSessionHasNoErrors();
        $this->assertSame('queued', app(Updates::class)->panelStatus()['state']);
    }

    public function test_bez_uslugi_aktualizacji_panel_mowi_co_zrobic(): void
    {
        unlink($this->dir.'/unit.path');

        $this->actingAs($this->admin)
            ->post(route('panel.admin.updates.panel'))
            ->assertSessionHasErrors('update');

        $this->assertFileDoesNotExist($this->dir.'/request');
    }

    public function test_status_i_log_aktualizacji_panelu(): void
    {
        file_put_contents($this->dir.'/status.json', json_encode(['state' => 'failed', 'message' => 'coś poszło nie tak']));
        file_put_contents($this->dir.'/last.log', "linia 1\nlinia 2\n");

        $status = app(Updates::class)->panelStatus();

        $this->assertSame('failed', $status['state']);
        $this->assertStringContainsString('linia 2', $status['log']);
    }

    public function test_zlecenie_aktualizacji_wezla(): void
    {
        $node = $this->node('bbbb');
        Http::fake(['*' => Http::response(['state' => 'queued'], 202)]);

        $this->actingAs($this->admin)
            ->post(route('panel.admin.updates.node', $node))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/system/update')
            && $request->hasHeader('X-VH-Signature'));
    }

    public function test_stary_agent_bez_zdalnych_aktualizacji(): void
    {
        $node = $this->node(null);
        Http::fake(['*' => Http::response(['detail' => 'Not Found'], 404)]);

        $this->actingAs($this->admin)
            ->post(route('panel.admin.updates.node', $node))
            ->assertSessionHasErrors('update');
    }

    public function test_aktualizacja_wszystkich_pomija_aktualne(): void
    {
        $current = $this->node(self::LATEST);
        $outdated = $this->node('bbbb');

        Http::fake([
            'api.github.com/*' => Http::response(['sha' => self::LATEST, 'html_url' => '', 'commit' => ['message' => 'x']]),
            '*' => Http::response(['state' => 'queued'], 202),
        ]);

        $this->actingAs($this->admin)->post(route('panel.admin.updates.nodes'))->assertSessionHasNoErrors();

        Http::assertSent(fn ($r) => str_starts_with($r->url(), $outdated->agent_url));
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), $current->agent_url));
    }

    public function test_strona_i_status_na_zywo(): void
    {
        $node = $this->node('bbbb');

        $this->actingAs($this->admin)
            ->get(route('panel.admin.updates'))
            ->assertOk()
            ->assertSee('Aktualizuj panel')
            ->assertSee($node->name)
            ->assertSee('dostępna nowsza');

        Http::fake([
            'api.github.com/*' => Http::response(['sha' => self::LATEST, 'html_url' => '', 'commit' => ['message' => 'x']]),
            '*' => Http::response(['state' => 'running', 'build' => 'bbbb', 'message' => 'Wdrażam']),
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('panel.admin.updates.status'))
            ->assertOk()
            ->assertJsonPath('nodes.0.state', 'running')
            ->assertJsonPath('nodes.0.message', 'Wdrażam');
    }

    public function test_klient_nie_ma_dostepu(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($customer)->get(route('panel.admin.updates'))->assertForbidden();
        $this->actingAs($customer)->post(route('panel.admin.updates.panel'))->assertForbidden();
        $this->assertFileDoesNotExist($this->dir.'/request');
    }
}
