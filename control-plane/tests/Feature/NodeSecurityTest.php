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

    private function nodeWith(array $overrides): Hypervisor
    {
        return Hypervisor::factory()->create(['enrolled_at' => now(), 'last_health' => ['host_security' => $overrides + $this->report('warning', false, 'mitigated')]]);
    }

    public function test_przelacznik_tylko_gdy_jadro_bez_poprawek(): void
    {
        $patched = $this->nodeWith(['kernel_patched' => true, 'nested_policy' => 'auto', 'hardener' => true, 'kvm' => ['module' => 'kvm_amd', 'nested' => true, 'tdp' => true]]);
        $this->actingAs($this->admin)->get(route('panel.admin.security'))
            ->assertOk()->assertSee('automatycznie: jądro ma poprawki')->assertDontSee('Włącz mimo braku poprawek');

        $patched->delete();
        $this->nodeWith(['kernel_patched' => false, 'nested_policy' => 'auto', 'hardener' => true]);
        $this->actingAs($this->admin)->get(route('panel.admin.security'))
            ->assertOk()->assertSee('Włącz mimo braku poprawek')->assertSee('jądro bez poprawek, więc wyłączona');

        // Support widzi stan, ale nie przełącznik.
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT, 'permissions' => ['admin.hypervisors']]);
        $this->actingAs($support)->get(route('panel.admin.security'))->assertOk()->assertDontSee('Włącz mimo braku poprawek');
    }

    public function test_wlaczenie_mimo_braku_poprawek_trafia_na_wezel(): void
    {
        $node = $this->nodeWith(['kernel_patched' => false, 'nested_policy' => 'auto', 'hardener' => true]);
        Http::fake(['*/system/nested' => Http::response(['policy' => 'allow', 'requested' => true])]);

        $this->actingAs($this->admin)->put(route('panel.admin.security.nested', $node), ['policy' => 'allow'])
            ->assertSessionHasErrors('policy');
        Http::assertNothingSent();

        $this->actingAs($this->admin)->put(route('panel.admin.security.nested', $node), ['policy' => 'allow', 'confirm' => '1'])
            ->assertSessionHasNoErrors();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/system/nested') && $r['policy'] === 'allow');
        $this->assertSame('allow', $node->fresh()->securityReport()['nested_policy']);

        $this->actingAs($this->admin)->put(route('panel.admin.security.nested', $node), ['policy' => 'auto'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('panel.admin.security.nested', $node), ['policy' => 'zle'])->assertSessionHasErrors('policy');

        $support = User::factory()->create(['role' => User::ROLE_SUPPORT, 'permissions' => ['admin.hypervisors']]);
        $this->actingAs($support)->put(route('panel.admin.security.nested', $node), ['policy' => 'allow', 'confirm' => '1'])->assertForbidden();
    }

    public function test_wezel_bez_uslugi_zabezpieczen(): void
    {
        $node = $this->nodeWith(['kernel_patched' => false, 'nested_policy' => 'auto', 'hardener' => false]);
        Http::fake(['*' => Http::response(['detail' => 'Ten węzeł nie ma jeszcze usługi zabezpieczeń'], 409)]);

        $this->actingAs($this->admin)->get(route('panel.admin.security'))->assertSee('nie ma jeszcze usługi zabezpieczeń');
        $this->actingAs($this->admin)->put(route('panel.admin.security.nested', $node), ['policy' => 'allow', 'confirm' => '1'])
            ->assertSessionHasErrors('policy');
    }
}
