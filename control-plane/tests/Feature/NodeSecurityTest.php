<?php

namespace Tests\Feature;

use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Infrastruktura → Bezpieczeństwo: ucieczki z maszyn (Januscape, Zapscape, ITScape). */
class NodeSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function report(string $overall, bool $nested, string $januscape): array
    {
        return [
            'checked_at' => time(), 'overall' => $overall, 'arch' => 'x86_64', 'cpu_vendor' => 'amd', 'kvm_host' => true,
            'kvm' => ['module' => 'kvm_amd', 'nested' => $nested, 'tdp' => true], 'os' => 'Debian GNU/Linux 12',
            'kernel' => ['running_kernel' => '6.1.0-30-amd64', 'newest_kernel' => '6.1.0-31-amd64', 'required' => true],
            'auto_updates' => true, 'smt' => 'on',
            'issues' => [
                ['id' => 'CVE-2026-53359', 'name' => 'Januscape', 'summary' => 'x', 'status' => $januscape, 'detail' => 'Szczegóły Januscape'],
                ['id' => 'CVE-2026-64561', 'name' => 'Zapscape', 'summary' => 'y', 'status' => 'partial', 'detail' => 'AMD: częściowo'],
            ],
            'findings' => [['severity' => 'warning', 'title' => 'Wymagany restart węzła', 'detail' => 'restart']],
            'cpu_vulnerabilities' => ['mds' => 'Vulnerable: no microcode', 'meltdown' => 'Not affected'],
        ];
    }

    public function test_strona_pokazuje_stan_i_ostrzezenie_na_pulpicie(): void
    {
        Hypervisor::factory()->create(['name' => 'waw-1', 'enrolled_at' => now(), 'last_health' => ['host_security' => $this->report('critical', true, 'vulnerable')]]);
        Hypervisor::factory()->create(['name' => 'old-agent', 'enrolled_at' => now(), 'last_health' => ['cpu_cores_total' => 4]]);

        $this->actingAs($this->admin)->get(route('panel.admin.security'))
            ->assertOk()
            ->assertSee('waw-1')->assertSee('Januscape')->assertSee('CVE-2026-53359')->assertSee('Szczegóły Januscape')
            ->assertSee('Wymagany restart węzła')->assertSee('6.1.0-31-amd64')
            ->assertSee('Vulnerable: no microcode')
            ->assertSee('zaktualizuj węzeł');

        $this->actingAs($this->admin)->get(route('panel.admin.index'))
            ->assertOk()->assertSee('Węzły podatne na ucieczki z maszyn: 1.')->assertSee(route('panel.admin.security'), false);
    }

    public function test_sprawdz_teraz_pobiera_swiezy_raport(): void
    {
        $node = Hypervisor::factory()->create(['enrolled_at' => now(), 'last_health' => ['host_security' => $this->report('critical', true, 'vulnerable'), 'cpu_cores_total' => 8]]);
        Http::fake(['*/system/security' => Http::response($this->report('warning', false, 'mitigated'))]);

        $this->actingAs($this->admin)->post(route('panel.admin.security.check', $node))->assertRedirect();

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/system/security') && $r->hasHeader('X-VH-Signature'));
        $node->refresh();
        $this->assertSame('warning', $node->securityReport()['overall']);
        $this->assertSame(8, $node->last_health['cpu_cores_total'], 'reszta raportu zostaje');
    }

    public function test_uprawnienia(): void
    {
        $node = Hypervisor::factory()->create(['enrolled_at' => now()]);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))->get(route('panel.admin.security'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_SUPPORT]))->post(route('panel.admin.security.check', $node))->assertForbidden();
    }
}
