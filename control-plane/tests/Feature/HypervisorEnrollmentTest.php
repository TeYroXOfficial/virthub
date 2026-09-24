<?php

namespace Tests\Feature;

use App\Domain\Provisioning\HypervisorEnrollment;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rejestracja węzła jednym poleceniem.
 *
 * Endpointy są publiczne, bo świeży serwer nie ma jeszcze żadnych poświadczeń —
 * całą ochronę niesie bilet. Stąd nacisk testów na to, co się dzieje, gdy bilet
 * jest zły, wygasły albo użyty po raz drugi.
 */
class HypervisorEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private HypervisorEnrollment $enrollment;

    private Hypervisor $hypervisor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enrollment = app(HypervisorEnrollment::class);
        $this->hypervisor = Hypervisor::factory()->create([
            'name' => 'node-testowy',
            'hostname' => 'oczekuje-na-rejestracje',
            'agent_url' => null,
            'enrolled_at' => null,
        ]);
        $this->token = $this->enrollment->issueToken($this->hypervisor);
    }

    private function report(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'node1.example.com',
            'cpu_cores' => 32,
            'ram_mb' => 65536,
            'disk_gb' => 2000,
            'tls_cert' => "-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----\n",
        ], $overrides);
    }

    // --- skrypt instalacyjny ------------------------------------------------

    public function test_skrypt_zawiera_bilet_i_adres_panelu(): void
    {
        $response = $this->get("/enroll/{$this->token}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/x-shellscript; charset=utf-8');

        $script = $response->getContent();
        $this->assertStringContainsString($this->token, $script);
        $this->assertStringContainsString(config('app.url'), $script);
        $this->assertStringContainsString('node-testowy', $script);

        // Podstawienia muszą być kompletne — placeholder w skrypcie oznacza,
        // że instalator wywali się dopiero na serwerze klienta.
        $this->assertStringNotContainsString('__', $script);
    }

    public function test_skrypt_nie_trafia_do_cache(): void
    {
        // Bilet siedzi w adresie — proxy nie ma prawa go zapamiętać.
        $this->get("/enroll/{$this->token}")
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_nieznany_bilet_konczy_sie_404(): void
    {
        $this->get('/enroll/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_wygasly_bilet_nie_dziala(): void
    {
        $this->hypervisor->forceFill([
            'enrollment_expires_at' => now()->subMinute(),
        ])->save();

        $this->get("/enroll/{$this->token}")->assertNotFound();
        $this->postJson("/enroll/{$this->token}/complete", $this->report())->assertNotFound();
    }

    // --- wykrywanie adresu --------------------------------------------------

    public function test_whoami_zwraca_adres_zrodlowy(): void
    {
        $this->get("/enroll/{$this->token}/whoami")
            ->assertOk()
            ->assertJsonStructure(['ip']);
    }

    // --- meldunek -----------------------------------------------------------

    public function test_meldunek_zapisuje_dane_wezla_i_zwraca_sekrety(): void
    {
        $response = $this->postJson("/enroll/{$this->token}/complete", $this->report());

        $response->assertOk()->assertJsonStructure(['agent_token', 'callback_secret']);

        $this->hypervisor->refresh();
        $this->assertSame('node1.example.com', $this->hypervisor->hostname);
        $this->assertNotNull($this->hypervisor->enrolled_at);
        $this->assertStringStartsWith('https://', $this->hypervisor->agent_url);
        $this->assertStringEndsWith(
            ':'.HypervisorEnrollment::AGENT_TLS_PORT,
            $this->hypervisor->agent_url,
        );

        // Sekrety w bazie muszą zgadzać się z tymi, które dostał instalator —
        // inaczej agent i panel nie dogadają się przy pierwszym zadaniu.
        $this->assertSame($response->json('agent_token'), $this->hypervisor->agent_token);
        $this->assertSame($response->json('callback_secret'), $this->hypervisor->callback_secret);
    }

    public function test_pojemnosc_jest_pomniejszona_o_zapas_dla_systemu_hosta(): void
    {
        $this->postJson("/enroll/{$this->token}/complete", $this->report([
            'cpu_cores' => 32,
            'ram_mb' => 65536,
            'disk_gb' => 2000,
        ]))->assertOk();

        $this->hypervisor->refresh();
        $this->assertSame(30, $this->hypervisor->cpu_cores_total, '2 rdzenie zostają dla hosta');
        $this->assertSame(61440, $this->hypervisor->ram_mb_total, '4 GB RAM zostaje dla hosta');
        $this->assertSame(1980, $this->hypervisor->disk_gb_total, '20 GB dysku zostaje dla hosta');
    }

    public function test_maly_wezel_nie_konczy_z_zerowa_pojemnoscia(): void
    {
        // Węzeł z zadeklarowaną zerową pojemnością wypadłby z doboru maszyn
        // i wyglądałby na zepsuty, zamiast po prostu mały.
        $this->postJson("/enroll/{$this->token}/complete", $this->report([
            'cpu_cores' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 20,
        ]))->assertOk();

        $this->hypervisor->refresh();
        $this->assertGreaterThan(0, $this->hypervisor->cpu_cores_total);
        $this->assertGreaterThan(0, $this->hypervisor->ram_mb_total);
        $this->assertGreaterThan(0, $this->hypervisor->disk_gb_total);
    }

    public function test_bilet_jest_jednorazowy(): void
    {
        $this->postJson("/enroll/{$this->token}/complete", $this->report())->assertOk();

        // Ponowne użycie nie może wygenerować drugiego kompletu sekretów —
        // to unieważniłoby agenta, który właśnie się zarejestrował.
        $this->postJson("/enroll/{$this->token}/complete", $this->report())->assertNotFound();
        $this->get("/enroll/{$this->token}")->assertNotFound();
    }

    public function test_wezel_po_rejestracji_czeka_na_heartbeat(): void
    {
        $this->postJson("/enroll/{$this->token}/complete", $this->report())->assertOk();

        $this->hypervisor->refresh();
        // Rejestracja nie oznacza jeszcze, że panel dodzwoni się do agenta —
        // online robi się dopiero po udanym heartbeacie.
        $this->assertSame(Hypervisor::STATUS_OFFLINE, $this->hypervisor->status);
        $this->assertFalse($this->hypervisor->isOnline());
    }

    public function test_certyfikat_w_zlym_formacie_jest_odrzucany(): void
    {
        $this->postJson("/enroll/{$this->token}/complete", $this->report([
            'tls_cert' => 'to nie jest certyfikat',
        ]))->assertStatus(422);

        $this->assertNull($this->hypervisor->fresh()->enrolled_at);
    }

    public function test_niekompletny_meldunek_jest_odrzucany(): void
    {
        $this->postJson("/enroll/{$this->token}/complete", ['hostname' => 'node1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cpu_cores', 'ram_mb', 'disk_gb', 'tls_cert']);
    }

    public function test_certyfikat_jest_przypinany_do_polaczen_z_wezlem(): void
    {
        $this->postJson("/enroll/{$this->token}/complete", $this->report())->assertOk();

        $path = $this->enrollment->certificatePath($this->hypervisor->fresh());

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertStringContainsString('BEGIN CERTIFICATE', file_get_contents($path));
    }

    public function test_wezel_bez_certyfikatu_nie_ma_przypiecia(): void
    {
        // Węzeł z własną domeną i certyfikatem publicznego urzędu weryfikuje się
        // normalnie — nie ma czego przypinać.
        $other = Hypervisor::factory()->create(['agent_tls_cert' => null]);

        $this->assertNull($this->enrollment->certificatePath($other));
    }

    // --- pakiet z kodem agenta ----------------------------------------------

    public function test_paczka_agenta_ma_uklad_oczekiwany_przez_instalator(): void
    {
        // Instalator szuka requirements.txt i agent/main.py. Kiedyś paczka i
        // instalator rozjechały się (--strip-components na płaskim archiwum)
        // i na serwerze zabrakło requirements.txt — ten test pilnuje kontraktu.
        config(['virthub.agent_source_path' => base_path('../node-agent')]);

        $response = $this->get("/enroll/{$this->token}/agent.tar.gz");
        $response->assertOk();

        $archive = new \PharData($response->baseResponse->getFile()->getPathname());
        $entries = [];
        foreach (new \RecursiveIteratorIterator($archive) as $file) {
            $entries[] = str_replace('\\', '/', substr($file->getPathname(), strlen('phar://'.$archive->getPath()) + 1));
        }

        $this->assertContains('requirements.txt', $entries);
        $this->assertContains('agent/main.py', $entries);
        $this->assertContains('systemd/virthub-agent.service', $entries);
        $this->assertEmpty(
            array_filter($entries, fn ($e) => preg_match('#(^|/)(\.venv|__pycache__)/|(^|/)\.env$#', $e)),
            'Do paczki nie może trafić środowisko Pythona, cache ani plik .env',
        );
    }

    public function test_instalator_nie_ucina_sciezek_archiwum(): void
    {
        $script = $this->get("/enroll/{$this->token}")->getContent();

        // Sprawdzamy wywołania tar, nie komentarze, które o tym błędzie opowiadają.
        $tarCalls = array_filter(
            explode("\n", $script),
            fn ($line) => preg_match('/^\s*tar\s/', $line),
        );

        $this->assertNotEmpty($tarCalls);
        foreach ($tarCalls as $call) {
            $this->assertStringNotContainsString('--strip-components', $call);
        }
    }

    public function test_brak_kodu_agenta_daje_czytelny_komunikat(): void
    {
        config(['virthub.agent_source_path' => '/sciezka/ktora/nie/istnieje']);

        $this->get("/enroll/{$this->token}/agent.tar.gz")
            ->assertStatus(503)
            ->assertSee('VIRTHUB_AGENT_SOURCE_PATH', escape: false);
    }

    // --- wystawianie biletu z panelu ----------------------------------------

    public function test_administrator_dodaje_wezel_i_dostaje_polecenie(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)->post(route('panel.admin.hypervisors.store'), [
            'name' => 'node2',
        ]);

        $response->assertRedirect(route('panel.admin.hypervisors'));
        $response->assertSessionHas('enrollment');

        $command = session('enrollment')['command'];
        $this->assertStringContainsString('curl -sSL', $command);
        $this->assertStringContainsString('| sudo bash', $command);

        $created = Hypervisor::where('name', 'node2')->firstOrFail();
        $this->assertTrue($created->isAwaitingEnrollment());
    }

    public function test_nie_da_sie_wygenerowac_biletu_dla_zarejestrowanego_wezla(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->hypervisor->forceFill(['enrolled_at' => now()])->save();

        $this->actingAs($admin)
            ->post(route('panel.admin.hypervisors.enrollment', $this->hypervisor))
            ->assertSessionHasErrors('enrollment');
    }
}
