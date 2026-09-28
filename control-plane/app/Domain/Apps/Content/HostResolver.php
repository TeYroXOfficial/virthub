<?php

namespace App\Domain\Apps\Content;

use Illuminate\Support\Facades\Cache;

/**
 * Szybkie rozwiązywanie nazw dla zapytań panelu do serwisów zewnętrznych.
 *
 * Systemowy resolver potrafi czekać po 5 s na każdą nazwę (np. węzeł
 * Hetznera po zamianie eth0 na most — zniknęły serwery DNS z konfiguracji
 * interfejsu, a w resolv.conf został nieosiągalny). Pytamy więc wprost
 * publiczne resolvery (UDP, 1 s na próbę), zapamiętujemy wynik i podajemy
 * adres curlowi (CURLOPT_RESOLVE) — wtedy curl w ogóle nie pyta systemu.
 * Gdy własne zapytanie się nie uda, zostaje zwykły resolver systemowy.
 */
class HostResolver
{
    private const MIN_TTL = 300;

    private const MAX_TTL = 3600;

    /** Testy wyłączają prawdziwe zapytania; test resolvera włącza je z powrotem. */
    public static bool $inTests = false;

    /** @return list<string> adresy IPv4 (pusta lista = niech curl rozwiąże sam) */
    public static function resolve(string $host): array
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || (app()->runningUnitTests() && ! self::$inTests)) {
            return [];
        }
        $servers = self::servers();
        if ($servers === [] || Cache::has('dns:unreachable')) {
            return [];
        }

        $key = 'dns:'.strtolower($host);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        foreach ($servers as $server) {
            [$ips, $ttl] = self::query($host, $server);
            if ($ips !== []) {
                Cache::put($key, $ips, max(self::MIN_TTL, min(self::MAX_TTL, $ttl)));

                return $ips;
            }
        }
        // Żaden resolver nie odpowiedział (np. zablokowany UDP 53) — przez
        // 10 minut nie próbujemy, żeby nie dokładać sekund do każdego zapytania.
        if (! self::reachable($servers)) {
            Cache::put('dns:unreachable', true, 600);
        }

        return [];
    }

    /** @param  list<string>  $servers */
    private static function reachable(array $servers): bool
    {
        foreach ($servers as $server) {
            if (self::query('one.one.one.one', $server)[0] !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function servers(): array
    {
        $raw = (string) config('virthub.content_dns', '1.1.1.1,9.9.9.9');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => filter_var($s, FILTER_VALIDATE_IP)));
    }

    /**
     * Zapytanie o rekord A (z rekurencją; CNAME rozwiązuje serwer).
     *
     * @return array{0: list<string>, 1: int} adresy i najkrótszy TTL
     */
    public static function query(string $host, string $server, float $timeout = 1.0): array
    {
        $id = random_int(0, 0xFFFF);
        $qname = '';
        foreach (explode('.', rtrim($host, '.')) as $label) {
            if ($label === '' || strlen($label) > 63) {
                return [[], 0];
            }
            $qname .= chr(strlen($label)).$label;
        }
        $packet = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0).$qname."\0".pack('nn', 1, 1);

        $socket = @stream_socket_client("udp://{$server}:53", $errno, $error, $timeout);
        if ($socket === false) {
            return [[], 0];
        }
        stream_set_timeout($socket, (int) $timeout, (int) (($timeout - floor($timeout)) * 1_000_000));
        @fwrite($socket, $packet);
        $response = @fread($socket, 4096);
        fclose($socket);

        return self::parse((string) $response, $id);
    }

    /** @return array{0: list<string>, 1: int} */
    public static function parse(string $data, int $id): array
    {
        if (strlen($data) < 12) {
            return [[], 0];
        }
        $header = unpack('nid/nflags/nqd/nan', substr($data, 0, 8));
        if ($header['id'] !== $id || ($header['flags'] & 0x000F) !== 0) {
            return [[], 0]; // cudza odpowiedź albo błąd (NXDOMAIN, SERVFAIL)
        }

        $offset = 12;
        for ($i = 0; $i < $header['qd']; $i++) {
            $offset = self::skipName($data, $offset) + 4;
        }

        $ips = [];
        $ttl = self::MAX_TTL;
        for ($i = 0; $i < $header['an'] && $offset < strlen($data); $i++) {
            $offset = self::skipName($data, $offset);
            if ($offset + 10 > strlen($data)) {
                break;
            }
            $rr = unpack('ntype/nclass/Nttl/nlen', substr($data, $offset, 10));
            $offset += 10;
            if ($rr['type'] === 1 && $rr['len'] === 4) {
                $ips[] = inet_ntop(substr($data, $offset, 4));
                $ttl = min($ttl, $rr['ttl']);
            }
            $offset += $rr['len'];
        }

        return [array_values(array_filter($ips)), $ttl];
    }

    private static function skipName(string $data, int $offset): int
    {
        $length = strlen($data);
        while ($offset < $length) {
            $len = ord($data[$offset]);
            if ($len === 0) {
                return $offset + 1;
            }
            if (($len & 0xC0) === 0xC0) {
                return $offset + 2; // wskaźnik kompresji kończy nazwę
            }
            $offset += $len + 1;
        }

        return $offset;
    }
}
