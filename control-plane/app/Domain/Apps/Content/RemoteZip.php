<?php

namespace App\Domain\Apps\Content;

/**
 * Jeden plik z archiwum zip na serwerze HTTP — bez pobierania całości.
 *
 * Modpack potrafi mieć setki MB (overrides), a panel potrzebuje z niego tylko
 * listy plików (modrinth.index.json / manifest.json). Zapytania Range czytają
 * koniec archiwum (katalog centralny, także ZIP64), potem sam wpis. Gdy serwer
 * nie obsługuje Range, zwracamy null i wywołujący pobiera archiwum w całości.
 */
class RemoteZip
{
    private const TAIL = 65557;            // EOCD (22 B) + najdłuższy komentarz (65535 B)

    private const MAX_DIRECTORY = 64 * 1024 * 1024;

    private const MAX_ENTRY = 64 * 1024 * 1024;

    /** Zawartość wpisu, null — serwer nie obsługuje Range (trzeba pobrać całość). */
    public static function read(string $url, string $entry): ?string
    {
        [$tail, $total] = self::range($url, 'bytes=-'.self::TAIL);
        if ($tail === null) {
            return null;
        }

        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false) {
            throw new ContentException(__('Archiwum paczki jest uszkodzone.'));
        }
        $eocd = unpack('Vsig/vdisk/vcddisk/ventries/vtotal/Vsize/Voffset', substr($tail, $pos, 20));
        $cdSize = $eocd['size'];
        $cdOffset = $eocd['offset'];

        // ZIP64: prawdziwe rozmiary w osobnym rekordzie, wskazanym tuż przed EOCD.
        if ($cdOffset === 0xFFFFFFFF || $cdSize === 0xFFFFFFFF) {
            $locator = strrpos(substr($tail, 0, $pos), "PK\x06\x07");
            if ($locator === false) {
                throw new ContentException(__('Archiwum paczki jest uszkodzone.'));
            }
            $recordOffset = unpack('P', substr($tail, $locator + 8, 8))[1];
            [$record] = self::range($url, 'bytes='.$recordOffset.'-'.($recordOffset + 55));
            if ($record === null || ! str_starts_with($record, "PK\x06\x06")) {
                throw new ContentException(__('Archiwum paczki jest uszkodzone.'));
            }
            $cdSize = unpack('P', substr($record, 40, 8))[1];
            $cdOffset = unpack('P', substr($record, 48, 8))[1];
        }
        if ($cdSize > self::MAX_DIRECTORY || $cdOffset + $cdSize > $total) {
            throw new ContentException(__('Archiwum paczki jest uszkodzone.'));
        }

        [$directory] = self::range($url, 'bytes='.$cdOffset.'-'.($cdOffset + $cdSize - 1));
        $found = self::find((string) $directory, $entry);
        if ($found === null) {
            throw new ContentException(__('W paczce brakuje :file.', ['file' => $entry]));
        }
        [$method, $compressed, $size, $localOffset] = $found;
        if ($size > self::MAX_ENTRY || $compressed > self::MAX_ENTRY) {
            throw new ContentException(__('Lista plików paczki jest za duża.'));
        }

        [$local] = self::range($url, 'bytes='.$localOffset.'-'.($localOffset + 29));
        if ($local === null || ! str_starts_with($local, "PK\x03\x04")) {
            throw new ContentException(__('Archiwum paczki jest uszkodzone.'));
        }
        $lengths = unpack('vname/vextra', substr($local, 26, 4));
        $start = $localOffset + 30 + $lengths['name'] + $lengths['extra'];
        [$data] = $compressed > 0 ? self::range($url, 'bytes='.$start.'-'.($start + $compressed - 1)) : [''];

        return match ($method) {
            0 => (string) $data,
            8 => @gzinflate((string) $data) ?: throw new ContentException(__('Archiwum paczki jest uszkodzone.')),
            default => throw new ContentException(__('Archiwum paczki używa nieobsługiwanej kompresji.')),
        };
    }

    /** @return array{0: int, 1: int, 2: int, 3: int}|null metoda, rozmiar skompresowany, rozmiar, przesunięcie nagłówka */
    private static function find(string $directory, string $entry): ?array
    {
        $offset = 0;
        $length = strlen($directory);
        while ($offset + 46 <= $length && substr($directory, $offset, 4) === "PK\x01\x02") {
            $h = unpack('vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($directory, $offset + 10, 36));
            $name = substr($directory, $offset + 46, $h['name']);
            if ($name === $entry) {
                // ZIP64: rozmiary/przesunięcie ponad 4 GB są w polu dodatkowym 0x0001.
                $extra = substr($directory, $offset + 46 + $h['name'], $h['extra']);
                $compressed = $h['compressed'];
                $size = $h['size'];
                $local = $h['offset'];
                for ($i = 0; $i + 4 <= strlen($extra);) {
                    $field = unpack('vid/vlen', substr($extra, $i, 4));
                    if ($field['id'] === 0x0001) {
                        $data = substr($extra, $i + 4, $field['len']);
                        $p = 0;
                        foreach (['size', 'compressed', 'local'] as $key) {
                            if ($$key === 0xFFFFFFFF && $p + 8 <= strlen($data)) {
                                $$key = unpack('P', substr($data, $p, 8))[1];
                                $p += 8;
                            }
                        }
                    }
                    $i += 4 + $field['len'];
                }

                return [$h['method'], $compressed, $size, $local];
            }
            $offset += 46 + $h['name'] + $h['extra'] + $h['comment'];
        }

        return null;
    }

    /** @return array{0: ?string, 1: int} treść zakresu i pełny rozmiar (null — brak obsługi Range) */
    private static function range(string $url, string $range): array
    {
        // Strumień: serwer bez obsługi Range odda całe archiwum (200) — wtedy
        // nie czytamy treści, tylko zgłaszamy, że trzeba pobrać całość.
        $response = ContentHttp::client(['Range' => $range, 'Accept' => '*/*'], 60)->withOptions(['stream' => true])->get($url);
        if ($response->status() !== 206) {
            // Treści nie czytamy — połączenie zamknie się wraz z odpowiedzią.
            if ($response->successful()) {
                return [null, 0];
            }
            throw new ContentException(__('Nie udało się pobrać paczki (HTTP :code).', ['code' => $response->status()]));
        }
        $total = preg_match('#/(\d+)$#', (string) $response->header('Content-Range'), $m) ? (int) $m[1] : 0;

        return [(string) $response->toPsrResponse()->getBody(), $total];
    }
}
