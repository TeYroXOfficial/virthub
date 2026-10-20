<?php

namespace Tests\Feature;

use App\Domain\Security\VulnerabilityFeed;
use App\Jobs\ScanVulnerabilitiesJob;
use App\Models\Hypervisor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_nowe_luki_z_bazy_osv_trafiaja_na_strone(): void
    {
        $report = $this->report('ok', false, 'mitigated') + ['packages' => ['ecosystem' => 'Debian:12', 'items' => [
            ['role' => 'kernel', 'name' => 'linux', 'version' => '6.1.129-1', 'binary' => 'linux-image-6.1.0-32-amd64'],
            ['role' => 'qemu', 'name' => 'qemu', 'version' => '1:7.2+dfsg-7+deb12u12', 'binary' => 'qemu-system-x86'],
        ]]];
        $report['findings'] = [];
        $node = Hypervisor::factory()->create(['name' => 'waw-1', 'enrolled_at' => now(), 'last_health' => ['host_security' => $report]]);

        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::sequence()
                ->push(['results' => [
                    ['vulns' => [['id' => 'DEBIAN-CVE-2026-1001'], ['id' => 'DEBIAN-CVE-2026-1002']], 'next_page_token' => 'p2'],
                    ['vulns' => [['id' => 'DEBIAN-CVE-2026-2001']]],
                ]])
                ->push(['results' => [['vulns' => [['id' => 'DEBIAN-CVE-2026-1003'], ['id' => 'DEBIAN-CVE-2026-99999'], ['id' => 'DEBIAN-CVE-2026-1004']]]]]),
            'api.osv.dev/v1/vulns/CVE-2026-1001' => Http::response(['id' => 'CVE-2026-1001', 'summary' => 'KVM: x86: use-after-free in shadow MMU', 'published' => now()->subDays(2)->toIso8601String()]),
            'api.osv.dev/v1/vulns/CVE-2026-1002' => Http::response(['id' => 'CVE-2026-1002', 'summary' => 'ext4: fix leak', 'published' => now()->subDays(3)->toIso8601String()]),
            'api.osv.dev/v1/vulns/CVE-2026-1003' => Http::response(['id' => 'CVE-2026-1003', 'summary' => 'KVM: s390: fix irq state', 'published' => now()->subDays(1)->toIso8601String()]),
            // PwnEscape: tytuł bez „KVM”, ale CVSS ze zmianą zakresu i nazwana ucieczka.
            'api.osv.dev/v1/vulns/CVE-2026-99999' => Http::response(['id' => 'CVE-2026-99999', 'summary' => 'ipv4: validate checksum_start before completing checksum',
                'published' => now()->subDays(5)->toIso8601String(), 'severity' => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:L/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H']]]),
            // Bez słowa KVM, ale z „guest-to-host” w opisie.
            'api.osv.dev/v1/vulns/CVE-2026-1004' => Http::response(['id' => 'CVE-2026-1004', 'summary' => 'tun: fix gso header length',
                'details' => 'A malicious VM can trigger a guest-to-host memory corruption.', 'published' => '2026-01-01T00:00:00Z']),
            'api.osv.dev/v1/vulns/DEBIAN-CVE-2026-99999' => Http::response(['affected' => [['package' => ['name' => 'linux', 'ecosystem' => 'Debian:12'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '6.1.190-1']]]]]]]),
            'api.osv.dev/v1/vulns/DEBIAN-CVE-2026-1004' => Http::response([], 404),
            'api.osv.dev/v1/vulns/CVE-2026-2001' => Http::response(['id' => 'CVE-2026-2001', 'details' => 'Heap overflow in virtio-blk', 'published' => '2025-01-01T00:00:00Z']),
            'api.osv.dev/v1/vulns/DEBIAN-CVE-2026-1001' => Http::response(['affected' => [['package' => ['name' => 'linux', 'ecosystem' => 'Debian:12'], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '6.1.140-1']]]]]]]),
            'api.osv.dev/v1/vulns/DEBIAN-CVE-2026-1003' => Http::response([], 404),
            'api.osv.dev/v1/vulns/DEBIAN-CVE-2026-2001' => Http::response(['affected' => [['package' => ['name' => 'qemu', 'ecosystem' => 'Debian:12'], 'ranges' => [['events' => [['introduced' => '0']]]]]]]),
        ]);

        $this->artisan('virthub:scan-vulnerabilities')->assertSuccessful();

        $this->actingAs($this->admin)->get(route('panel.admin.security'))
            ->assertOk()
            ->assertSee('CVE-2026-1001')->assertSee('KVM: x86: use-after-free in shadow MMU')->assertSee('6.1.140-1')
            ->assertDontSee('KVM: s390')             // inna architektura
            ->assertDontSee('ext4: fix leak')         // poza wirtualizacją — tylko liczba
            ->assertSee('CVE-2026-2001')->assertSee('Heap overflow in virtio-blk')
            ->assertSee('security-tracker.debian.org/tracker/CVE-2026-1001', false)
            ->assertSee(__('nowa'))
            ->assertSee('CVE-2026-99999')->assertSee('PwnEscape')->assertSee('6.1.190-1')
            ->assertSee('tun: fix gso header length');

        // Poprawka jest w repozytorium → węzeł podatny, mimo że audyt agenta mówi „ok”.
        $this->assertSame('critical', app(VulnerabilityFeed::class)->overall($node->fresh()));

        // Kolejne sprawdzenie nie pobiera znanych opisów ponownie.
        $described = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/vulns/CVE-'))->count();
        $this->artisan('virthub:scan-vulnerabilities')->assertSuccessful();
        $this->assertSame($described, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/vulns/CVE-'))->count());
    }

    public function test_dlugie_sprawdzenie_dzieli_sie_na_porcje_w_kolejce(): void
    {
        $report = $this->report('ok', false, 'mitigated') + ['packages' => ['ecosystem' => 'Debian:13', 'items' => [
            ['role' => 'kernel', 'name' => 'linux', 'version' => '6.12.107-1', 'binary' => 'linux-image-6.12.107+deb13-cloud-amd64'],
        ]]];
        $node = Hypervisor::factory()->create(['enrolled_at' => now(), 'last_health' => ['host_security' => $report]]);
        $ids = array_map(fn ($n) => ['id' => 'DEBIAN-CVE-2026-'.(5000 + $n)], range(1, 90));
        Http::fake([
            'api.osv.dev/v1/querybatch' => Http::response(['results' => [['vulns' => $ids]]]),
            'api.osv.dev/v1/vulns/*' => Http::response(['summary' => 'net: fix something', 'published' => '2026-01-01T00:00:00Z']),
        ]);

        // Czas się skończył od razu: lista CVE zapisana, opisów brak, zadanie zleca ciąg dalszy.
        $feed = app(VulnerabilityFeed::class);
        $first = $feed->scan(null, microtime(true) - 1);
        $this->assertFalse($first['complete']);
        $this->assertSame([0, 90], $feed->progress($node));
        $this->actingAs($this->admin)->get(route('panel.admin.security'))->assertSee('0 z 90', false);
        $this->artisan('virthub:scan-vulnerabilities --if-pending')->expectsOutputToContain('Sprawdzono 1');

        $this->assertSame([90, 90], $feed->progress($node));
        $this->artisan('virthub:scan-vulnerabilities --if-pending')->doesntExpectOutputToContain('Sprawdzono');

        Queue::fake();
        (new ScanVulnerabilitiesJob($node->id))->handle($feed);
        Queue::assertNothingPushed();
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
