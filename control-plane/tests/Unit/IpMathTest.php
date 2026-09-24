<?php

namespace Tests\Unit;

use App\Domain\Network\IpMath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IpMathTest extends TestCase
{
    public function test_cidr_jest_normalizowany_do_adresu_sieci(): void
    {
        $this->assertSame('203.0.113.0/24', IpMath::normalizeCidr('203.0.113.77/24'));
        $this->assertSame('2001:db8:10::/64', IpMath::normalizeCidr('2001:DB8:10:0::abcd/64'));
    }

    public function test_bledny_cidr_jest_odrzucany(): void
    {
        foreach (['203.0.113.0', '203.0.113.0/33', 'abc/24', '2001:db8::/129', '10.0.0.0/x'] as $bad) {
            try {
                IpMath::parseCidr($bad);
                $this->fail("Przyjęto błędny CIDR {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_przynaleznosc_do_podsieci(): void
    {
        $this->assertTrue(IpMath::contains('10.10.0.0/24', '10.10.0.200'));
        $this->assertFalse(IpMath::contains('10.10.0.0/24', '10.10.1.1'));
        $this->assertTrue(IpMath::contains('2001:db8:10::/64', '2001:db8:10::ffff:1'));
        $this->assertFalse(IpMath::contains('2001:db8:10::/64', '2001:db8:11::1'));
        $this->assertFalse(IpMath::contains('10.10.0.0/24', '::1'), 'Inna rodzina adresów nigdy nie należy');
    }

    public function test_dodawanie_przenosi_miedzy_bajtami(): void
    {
        $this->assertSame('10.0.1.0', IpMath::add('10.0.0.255', 1));
        $this->assertSame('2001:db8::1:0', IpMath::add('2001:db8::ffff', 1));
        $this->assertSame('2001:db8::1:0:0', IpMath::add('2001:db8::', 2 ** 32));
    }

    public function test_przesuniecie_jest_odwrotnoscia_dodawania(): void
    {
        $this->assertSame(300, IpMath::offset('10.0.0.0', IpMath::add('10.0.0.0', 300)));
        $this->assertSame(70000, IpMath::offset('2001:db8::', IpMath::add('2001:db8::', 70000)));
        $this->assertNull(IpMath::offset('10.0.0.5', '10.0.0.1'), 'Adres przed bazą');
        $this->assertNull(IpMath::offset('2001:db8::', '2001:db9::'), 'Różnica nie mieści się w int');
    }

    public function test_ostatni_adres_i_rozmiar(): void
    {
        $this->assertSame('203.0.113.255', IpMath::lastAddress('203.0.113.0/24'));
        $this->assertSame('2001:db8:10:0:ffff:ffff:ffff:ffff', IpMath::lastAddress('2001:db8:10::/64'));
        $this->assertSame(256, IpMath::size('203.0.113.0/24'));
        $this->assertSame(PHP_INT_MAX, IpMath::size('2001:db8::/64'));
    }

    public function test_porownanie_zgodne_z_kolejnoscia_adresow(): void
    {
        $this->assertSame(-1, IpMath::compare('10.0.0.9', '10.0.0.10'));
        $this->assertSame(1, IpMath::compare('2001:db8::10', '2001:db8::9'));
        $this->assertSame(0, IpMath::compare('2001:db8::1', '2001:DB8:0::1'));
    }
}
