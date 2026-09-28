<?php

namespace Tests\Unit;

use App\Domain\Apps\Content\RemoteZip;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Odczyt jednego pliku z archiwum paczki przez HTTP Range — bez pobierania całości. */
class RemoteZipTest extends TestCase
{
    private function zip(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rz');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('overrides/big.bin', random_bytes(3 * 1024 * 1024)); // niekompresowalne „overrides”
        $zip->addFromString('modrinth.index.json', json_encode(['files' => [['path' => 'mods/a.jar']], 'pad' => str_repeat('x', 5000)]));
        $zip->addFromString('stored.txt', 'bez kompresji');
        $zip->setCompressionName('stored.txt', \ZipArchive::CM_STORE);
        $zip->close();
        $data = file_get_contents($tmp);
        unlink($tmp);

        return $data;
    }

    public function test_czyta_wpis_zakresami_bez_pobierania_calosci(): void
    {
        $zip = $this->zip();
        $served = 0;
        Http::fake(['cdn.test/*' => function (Request $r) use ($zip, &$served) {
            preg_match('/bytes=(\d*)-(\d*)/', $r->header('Range')[0], $m);
            $len = strlen($zip);
            [$from, $to] = $m[1] === '' ? [max(0, $len - (int) $m[2]), $len - 1] : [(int) $m[1], min($len - 1, (int) $m[2])];
            $part = substr($zip, $from, $to - $from + 1);
            $served += strlen($part);

            return Http::response($part, 206, ['Content-Range' => "bytes {$from}-{$to}/{$len}"]);
        }]);

        $json = json_decode(RemoteZip::read('https://cdn.test/pack.mrpack', 'modrinth.index.json'), true);
        $this->assertSame('mods/a.jar', $json['files'][0]['path']);
        $this->assertSame('bez kompresji', RemoteZip::read('https://cdn.test/pack.mrpack', 'stored.txt'));
        $this->assertLessThan(strlen($zip) / 10, $served / 2); // na odczyt ułamek archiwum
    }

    public function test_serwer_bez_range_zwraca_null(): void
    {
        Http::fake(['cdn.test/*' => Http::response($this->zip(), 200)]);
        $this->assertNull(RemoteZip::read('https://cdn.test/pack.mrpack', 'modrinth.index.json'));
    }

    public function test_brak_wpisu_to_czytelny_blad(): void
    {
        $zip = $this->zip();
        Http::fake(['cdn.test/*' => function (Request $r) use ($zip) {
            preg_match('/bytes=(\d*)-(\d*)/', $r->header('Range')[0], $m);
            $len = strlen($zip);
            [$from, $to] = $m[1] === '' ? [max(0, $len - (int) $m[2]), $len - 1] : [(int) $m[1], min($len - 1, (int) $m[2])];

            return Http::response(substr($zip, $from, $to - $from + 1), 206, ['Content-Range' => "bytes {$from}-{$to}/{$len}"]);
        }]);
        $this->expectExceptionMessage('manifest.json');
        RemoteZip::read('https://cdn.test/pack.zip', 'manifest.json');
    }
}
