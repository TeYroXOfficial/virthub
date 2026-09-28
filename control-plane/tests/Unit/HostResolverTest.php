<?php

namespace Tests\Unit;

use App\Domain\Apps\Content\HostResolver;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Własny resolver DNS panelu (omija wolny DNS systemu). */
class HostResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        HostResolver::$inTests = false;
        parent::tearDown();
    }

    private function answer(int $id, int $rcode = 0): string
    {
        $q = "\x03api\x08modrinth\x03com\x00".pack('nn', 1, 1);
        $header = pack('nnnnnn', $id, 0x8180 | $rcode, 1, 3, 0, 0);
        // CNAME (pomijany) + dwa rekordy A z nazwą jako wskaźnikiem kompresji.
        $cname = "\xC0\x0C".pack('nnNn', 5, 1, 60, 6)."\x03cdn\xC0\x10";
        $a1 = "\xC0\x0C".pack('nnNn', 1, 1, 300, 4).inet_pton('104.18.22.35');
        $a2 = "\xC0\x0C".pack('nnNn', 1, 1, 120, 4).inet_pton('104.18.23.35');

        return $header.$q.$cname.$a1.$a2;
    }

    public function test_odpowiedz_dns_jest_czytana_z_kompresja_i_cname(): void
    {
        [$ips, $ttl] = HostResolver::parse($this->answer(4242), 4242);
        $this->assertSame(['104.18.22.35', '104.18.23.35'], $ips);
        $this->assertSame(120, $ttl);
    }

    public function test_cudza_odpowiedz_i_bledy_sa_odrzucane(): void
    {
        $this->assertSame([], HostResolver::parse($this->answer(1), 2)[0]);          // inny identyfikator
        $this->assertSame([], HostResolver::parse($this->answer(7, 3), 7)[0]);       // NXDOMAIN
        $this->assertSame([], HostResolver::parse("\x00\x01", 1)[0]);                // za krótka
    }

    public function test_niedostepne_resolvery_nie_spowalniaja_kolejnych_zapytan(): void
    {
        HostResolver::$inTests = true;
        config(['virthub.content_dns' => '127.0.0.1']); // nic tu nie nasłuchuje na 53

        $start = microtime(true);
        $this->assertSame([], HostResolver::resolve('api.modrinth.com'));
        $this->assertTrue(Cache::has('dns:unreachable'));
        $this->assertSame([], HostResolver::resolve('cdn.modrinth.com'));
        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function test_bez_resolverow_zostaje_dns_systemu(): void
    {
        HostResolver::$inTests = true;
        config(['virthub.content_dns' => '']);
        $this->assertSame([], HostResolver::resolve('api.modrinth.com'));
        $this->assertSame([], HostResolver::resolve('1.2.3.4'));
    }
}
