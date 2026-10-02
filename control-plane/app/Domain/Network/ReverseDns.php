<?php

namespace App\Domain\Network;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * rDNS adresów: zapis w panelu i rekord PTR w PowerDNS (API HTTP).
 *
 * Rekord PTR trafia do strefy odwrotnej, którą PowerDNS już obsługuje
 * (np. 113.0.203.in-addr.arpa) — wybieramy najdłuższą pasującą. Bez
 * skonfigurowanego PowerDNS rDNS zostaje tylko w panelu (np. do przepisania
 * u dostawcy). Klient może ustawić tylko nazwę, która wraca rekordem A/AAAA
 * do tego adresu (FCrDNS) — to wymaganie większości dostawców i ochrona
 * przed podszywaniem się pod cudze domeny.
 */
class ReverseDns
{
    public const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    public function __construct(private readonly ForwardResolver $resolver) {}

    /** @return array{url: ?string, server: string, ttl: int, has_key: bool, require_forward: bool} */
    public static function settings(): array
    {
        return [
            'url' => Setting::get('dns.pdns_url'),
            'server' => Setting::get('dns.pdns_server', 'localhost'),
            'ttl' => (int) Setting::get('dns.ttl', '3600'),
            'has_key' => filled(Setting::get('dns.pdns_key')),
            'require_forward' => Setting::get('dns.require_forward', '1') === '1',
        ];
    }

    public static function configured(): bool
    {
        return filled(Setting::get('dns.pdns_url')) && filled(Setting::get('dns.pdns_key'));
    }

    /**
     * Ustawia albo czyści rDNS adresu. Klient: nazwa musi wskazywać na ten adres.
     *
     * @throws ValidationException
     */
    public function set(IpAddress $address, ?string $hostname, User $actor, bool $asStaff): void
    {
        $hostname = $hostname === null ? null : rtrim(strtolower(trim($hostname)), '.');
        $hostname = $hostname === '' ? null : $hostname;

        if ($hostname !== null) {
            if (! preg_match(self::HOSTNAME, $hostname)) {
                $this->fail(__('Podaj pełną nazwę domenową, np. mail.example.com.'));
            }
            if (! $asStaff && self::settings()['require_forward'] && ! in_array($address->address, $this->resolver->addresses($hostname), true)) {
                $this->fail(__('Nazwa :host nie wskazuje na :address. Najpierw dodaj rekord :type :host → :address w DNS domeny, potem ustaw rDNS.', [
                    'host' => $hostname, 'address' => $address->address, 'type' => $address->version === 6 ? 'AAAA' : 'A',
                ]));
            }
        }

        if (self::configured()) {
            $this->push($address, $hostname);
        }

        $before = $address->rdns;
        $address->forceFill(['rdns' => $hostname])->save();
        AuditLog::record('ip.rdns', $address->server ?? $address->pool, ['address' => $address->address, 'from' => $before, 'to' => $hostname], $actor);
    }

    /** Przy zwolnieniu adresu: usuń PTR, żeby następny właściciel nie dostał cudzej nazwy. Błędy tylko logujemy. */
    public function forget(IpAddress $address): void
    {
        if ($address->rdns === null || ! self::configured()) {
            return;
        }
        try {
            $this->push($address, null);
        } catch (Throwable $e) {
            Log::warning('Nie udało się usunąć rekordu PTR', ['address' => $address->address, 'error' => $e->getMessage()]);
        }
    }

    /** Nazwa rekordu PTR, np. 14.113.0.203.in-addr.arpa. */
    public static function ptrName(string $address): string
    {
        if (IpMath::version($address) === 4) {
            return implode('.', array_reverse(explode('.', $address))).'.in-addr.arpa.';
        }
        $hex = bin2hex((string) inet_pton($address));

        return implode('.', array_reverse(str_split($hex))).'.ip6.arpa.';
    }

    /**
     * Sprawdzenie połączenia z PowerDNS — lista stref odwrotnych.
     *
     * @return list<string>
     */
    public function reverseZones(): array
    {
        $response = $this->client()->get($this->base().'/zones');
        if (! $response->successful()) {
            $this->fail(__('PowerDNS odpowiedział błędem :status: :body', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 200)]));
        }

        return collect($response->json())->pluck('name')
            ->filter(fn ($n) => is_string($n) && (str_ends_with($n, '.in-addr.arpa.') || str_ends_with($n, '.ip6.arpa.')))
            ->values()->all();
    }

    private function push(IpAddress $address, ?string $hostname): void
    {
        $ptr = self::ptrName($address->address);
        $zone = collect($this->reverseZonesQuietly())
            ->filter(fn (string $z) => str_ends_with($ptr, '.'.$z) || $ptr === $z)
            ->sortByDesc(fn (string $z) => strlen($z))->first();

        if ($zone === null) {
            $this->fail(__('PowerDNS nie ma strefy odwrotnej dla :address — dodaj w nim strefę (np. :example), a potem ustaw rDNS.', [
                'address' => $address->address,
                'example' => IpMath::version($address->address) === 4
                    ? implode('.', array_slice(explode('.', $ptr), 1))
                    : '…ip6.arpa.',
            ]));
        }

        $rrset = $hostname === null
            ? ['name' => $ptr, 'type' => 'PTR', 'changetype' => 'DELETE']
            : ['name' => $ptr, 'type' => 'PTR', 'ttl' => self::settings()['ttl'], 'changetype' => 'REPLACE',
                'records' => [['content' => $hostname.'.', 'disabled' => false]]];

        $response = $this->client()->patch($this->base().'/zones/'.rawurlencode($zone), ['rrsets' => [$rrset]]);
        if (! $response->successful()) {
            $this->fail(__('PowerDNS nie zapisał rekordu PTR (:status): :body', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 200)]));
        }
    }

    /** @return list<string> */
    private function reverseZonesQuietly(): array
    {
        try {
            return $this->reverseZones();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->fail(__('Brak połączenia z PowerDNS: :error', ['error' => $e->getMessage()]));
        }
    }

    private function client(): PendingRequest
    {
        $key = Setting::get('dns.pdns_key');
        try {
            $key = $key ? Crypt::decryptString($key) : '';
        } catch (Throwable) {
            $key = '';
        }

        return Http::timeout(10)->acceptJson()->withHeaders(['X-API-Key' => $key]);
    }

    private function base(): string
    {
        return rtrim((string) Setting::get('dns.pdns_url'), '/').'/api/v1/servers/'.rawurlencode((string) Setting::get('dns.pdns_server', 'localhost'));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['rdns' => $message]);
    }
}
