<?php

namespace App\Domain\Network;

use App\Domain\Provisioning\IpAllocator;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tworzenie i usuwanie pul adresów — wspólne dla panelu i API administratora.
 *
 * Reguły formularza sprawdzają kształt danych, a tutaj sprawdzamy to, co
 * wymaga wiedzy o sieci: czy brama i zakres leżą w podsieci, czy pula nie
 * nachodzi na inną i czy bloki portów NAT nie zderzą się na wspólnym węźle.
 */
class IpPoolManager
{
    /** Sieci dopuszczone za NAT-em: RFC 1918, CGNAT (RFC 6598) i ULA IPv6. */
    private const PRIVATE_NETWORKS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fc00::/7'];

    /** Gotowe zestawy resolverów DNS dla bloku — osobno dla IPv4 i IPv6. */
    public const DNS_PRESETS = [
        'cloudflare' => ['Cloudflare', ['1.1.1.1', '1.0.0.1'], ['2606:4700:4700::1111', '2606:4700:4700::1001']],
        'google' => ['Google', ['8.8.8.8', '8.8.4.4'], ['2001:4860:4860::8888', '2001:4860:4860::8844']],
        'quad9' => ['Quad9', ['9.9.9.9', '149.112.112.112'], ['2620:fe::fe', '2620:fe::9']],
        'opendns' => ['OpenDNS', ['208.67.222.222', '208.67.220.220'], ['2620:119:35::35', '2620:119:53::53']],
        'adguard' => ['AdGuard', ['94.140.14.14', '94.140.15.15'], ['2a10:50c0::ad1:ff', '2a10:50c0::ad2:ff']],
    ];

    /** Najwięcej adresów dodawanych jednym ruchem (zakres albo podsieć). */
    public const MAX_ADD = 65536;

    /** Najwięcej adresów IPv6 dodawanych jednym ruchem — IPv6 i tak powstaje na żądanie. */
    public const MAX_ADD_V6 = 4096;

    public function __construct(private readonly IpAllocator $allocator) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(['hypervisor', 'group'])],
            'hypervisor_id' => ['nullable', 'required_unless:scope,group', 'exists:hypervisors,id'],
            'hypervisor_group_id' => ['nullable', 'required_if:scope,group', 'exists:hypervisor_groups,id'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(IpPool::TYPES)],
            'cidr' => ['required', 'string', 'max:64'],
            'gateway' => ['required', 'ip'],
            'prefix' => ['required', 'integer', 'min:1', 'max:128'],
            'range_from' => ['nullable', 'ip'],
            'range_to' => ['nullable', 'ip'],
            'nameservers' => ['nullable'],
            'dns_preset' => ['nullable', Rule::in([...array_keys(self::DNS_PRESETS), 'custom', 'default'])],
            'fill' => ['nullable', Rule::in(['subnet', 'range', 'none'])],
            'nat_public_address' => ['nullable', 'ip'],
            'nat_port_start' => ['nullable', 'integer', 'min:1024', 'max:65535'],
            'nat_ports_per_server' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  dane po walidacji rules()
     * @return array{pool: IpPool, imported: int}
     *
     * @throws ValidationException
     */
    public function create(array $data): array
    {
        $attributes = $this->normalize($data);

        return DB::transaction(function () use ($attributes, $data) {
            $pool = new IpPool($attributes);
            $this->assertNoConflicts($pool);
            $pool->save();

            // „Pusty blok” — adresy admin dodaje potem pojedynczo, zakresem albo podsiecią.
            $imported = ($data['fill'] ?? null) === 'none' ? 0 : $this->allocator->importPool($pool);

            AuditLog::record('ip_pool.imported', $pool, [
                'cidr' => $pool->cidr,
                'type' => $pool->type,
                'scope' => $pool->isGroupPool() ? 'group:'.$pool->hypervisor_group_id : 'hypervisor:'.$pool->hypervisor_id,
                'imported' => $imported,
            ]);

            return ['pool' => $pool, 'imported' => $imported];
        });
    }

    /** @throws ValidationException */
    public function delete(IpPool $pool): void
    {
        if ($pool->addresses()->whereNotNull('server_id')->exists()) {
            throw ValidationException::withMessages([
                'pool' => __('Z puli :name korzystają maszyny. Zwolnij adresy albo usuń maszyny, ', ['name' => $pool->name])
                    .__('zanim usuniesz pulę.'),
            ]);
        }

        AuditLog::record('ip_pool.deleted', $pool, ['cidr' => $pool->cidr]);
        $pool->delete();
    }

    /** @return array<string, mixed> reguły edycji bloku (podsieci i rodzaju nie zmieniamy) */
    public static function updateRules(): array
    {
        return [
            'scope' => ['required', Rule::in(['hypervisor', 'group'])],
            'hypervisor_id' => ['nullable', 'required_if:scope,hypervisor', 'exists:hypervisors,id'],
            'hypervisor_group_id' => ['nullable', 'required_if:scope,group', 'exists:hypervisor_groups,id'],
            'name' => ['required', 'string', 'max:100'],
            'gateway' => ['required', 'ip'],
            'prefix' => ['required', 'integer', 'min:1', 'max:128'],
            'nameservers' => ['nullable'],
            'dns_preset' => ['nullable', Rule::in([...array_keys(self::DNS_PRESETS), 'custom', 'default'])],
            'nat_public_address' => ['nullable', 'ip'],
        ];
    }

    /**
     * Edycja bloku: nazwa, brama, maska, resolvery, przypisanie do węzła albo
     * grupy, adres wyjścia NAT. Wolne adresy przechodzą razem z blokiem;
     * przydzielone zostają przy węźle swojej maszyny do czasu zwolnienia.
     *
     * @param  array<string, mixed>  $data  dane po walidacji updateRules()
     *
     * @throws ValidationException
     */
    public function update(IpPool $pool, array $data): IpPool
    {
        $version = $pool->version;
        $maxPrefix = $version === 4 ? 32 : 128;
        if ((int) $data['prefix'] > $maxPrefix) {
            $this->fail('prefix', __('Maska dla maszyny w IPv:version może mieć najwyżej /:maxprefix.', ['version' => $version, 'maxprefix' => $maxPrefix]));
        }
        $gateway = $this->address($data['gateway'], $version, 'gateway', 'Brama');
        if ($pool->isNat() && ! IpMath::contains($pool->cidr, $gateway)) {
            $this->fail('gateway', __('Brama puli NAT musi leżeć w podsieci :cidr — to adres węzła na mostku NAT.', ['cidr' => $pool->cidr]));
        }
        $group = $data['scope'] === 'group';
        $publicAddress = $pool->isNat() && ! empty($data['nat_public_address'])
            ? $this->address($data['nat_public_address'], $version, 'nat_public_address', __('Adres wyjścia'))
            : null;

        return DB::transaction(function () use ($pool, $data, $gateway, $group, $publicAddress, $version) {
            $before = $pool->only(['hypervisor_id', 'hypervisor_group_id', 'gateway', 'name']);
            $pool->fill([
                'name' => $data['name'],
                'gateway' => $gateway,
                'prefix' => (int) $data['prefix'],
                'nameservers' => $this->resolvers($data, $version),
                'hypervisor_id' => $group ? null : (int) $data['hypervisor_id'],
                'hypervisor_group_id' => $group ? (int) $data['hypervisor_group_id'] : null,
                'nat_public_address' => $publicAddress,
            ]);
            if ($pool->isDirty(['hypervisor_id', 'hypervisor_group_id'])) {
                $this->assertNoConflicts($pool);
            }
            $pool->save();

            // Wolne adresy idą za blokiem; brama zawsze zarezerwowana.
            $pool->addresses()->whereNull('server_id')->update(['hypervisor_id' => $pool->hypervisor_id]);
            if ($before['gateway'] !== $gateway) {
                $pool->addresses()->where('address', $gateway)->whereNull('server_id')->update(['is_reserved' => true]);
            }

            AuditLog::record('ip_pool.updated', $pool, ['before' => $before, 'after' => $pool->only(array_keys($before))]);

            return $pool;
        });
    }

    /**
     * Dodaje adresy do bloku: pojedyncze (lista), zakres od–do albo podsieć
     * leżącą w podsieci bloku. Istniejące adresy są pomijane.
     *
     * @param  array{mode: string, addresses?: ?string, from?: ?string, to?: ?string, cidr?: ?string}  $data
     * @return array{added: int, skipped: int}
     *
     * @throws ValidationException
     */
    public function addAddresses(IpPool $pool, array $data): array
    {
        $version = $pool->version;
        $list = match ($data['mode']) {
            'single' => $this->singleAddresses($pool, (string) ($data['addresses'] ?? '')),
            'range' => $this->rangeAddresses($pool, (string) ($data['from'] ?? ''), (string) ($data['to'] ?? '')),
            'subnet' => $this->subnetAddresses($pool, (string) ($data['cidr'] ?? '')),
            default => $this->fail('mode', __('Nieznany sposób dodawania adresów.')),
        };

        if ($pool->isNat() && $pool->nat_port_start) {
            foreach ([$list[0] ?? null, end($list) ?: null] as $edge) {
                if ($edge !== null && $pool->natPortsFor($edge) === null) {
                    $this->fail('addresses', __('Adres :address nie dostanie bloku portów poniżej 65535 — zmniejsz liczbę portów na maszynę albo wybierz adresy bliżej początku sieci.', ['address' => $edge]));
                }
            }
        }

        $now = now();
        $rows = array_map(fn (string $address) => [
            'ip_pool_id' => $pool->id,
            'hypervisor_id' => $pool->hypervisor_id,
            'address' => $address,
            'scope_key' => $pool->scopeKey(),
            'version' => $version,
            'is_reserved' => IpMath::compare($address, $pool->gateway) === 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $list);

        $added = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $added += IpAddress::query()->insertOrIgnore($chunk);
        }

        AuditLog::record('ip_pool.addresses_added', $pool, ['mode' => $data['mode'], 'added' => $added, 'requested' => count($list)]);

        return ['added' => $added, 'skipped' => count($list) - $added];
    }

    /** @return list<string> */
    private function singleAddresses(IpPool $pool, string $input): array
    {
        $items = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $input) ?: []), fn ($a) => $a !== ''));
        if ($items === []) {
            $this->fail('addresses', __('Podaj przynajmniej jeden adres.'));
        }
        if (count($items) > 1024) {
            $this->fail('addresses', __('Jednorazowo możesz wkleić najwyżej 1024 adresy — większe ilości dodaj zakresem albo podsiecią.'));
        }

        return array_values(array_unique(array_map(fn ($a) => $this->inBlock($pool, $a, 'addresses'), $items)));
    }

    /** @return list<string> */
    private function rangeAddresses(IpPool $pool, string $from, string $to): array
    {
        $first = $this->inBlock($pool, $from, 'from');
        $last = $this->inBlock($pool, $to, 'to');
        if (IpMath::compare($first, $last) > 0) {
            $this->fail('to', __('Koniec zakresu nie może być przed jego początkiem.'));
        }
        $count = IpMath::offset($first, $last) + 1;
        $max = $pool->version === 4 ? self::MAX_ADD : self::MAX_ADD_V6;
        if ($count > $max) {
            $this->fail('to', __('Jednorazowo możesz dodać najwyżej :max adresów.', ['max' => $max]));
        }

        return array_map(fn (int $i) => IpMath::add($first, $i), range(0, $count - 1));
    }

    /** @return list<string> */
    private function subnetAddresses(IpPool $pool, string $cidr): array
    {
        if ($pool->version !== 4) {
            $this->fail('cidr', __('Podsieci IPv6 nie rozwijamy na adresy — blok IPv6 przydziela je sam przy zamówieniach. Dodaj zakres albo pojedyncze adresy.'));
        }
        try {
            $cidr = IpMath::normalizeCidr($cidr !== '' ? $cidr : $pool->cidr);
        } catch (\InvalidArgumentException) {
            $this->fail('cidr', __('Podaj podsieć w notacji CIDR, np. 203.0.113.0/28.'));
        }
        if (IpMath::parseCidr($cidr)['version'] !== 4
            || ! IpMath::contains($pool->cidr, IpMath::networkAddress($cidr))
            || ! IpMath::contains($pool->cidr, IpMath::lastAddress($cidr))) {
            $this->fail('cidr', __('Podsieć :cidr musi leżeć w podsieci bloku :block.', ['cidr' => $cidr, 'block' => $pool->cidr]));
        }
        $size = IpMath::size($cidr);
        if ($size > self::MAX_ADD) {
            $this->fail('cidr', __('Jednorazowo możesz dodać najwyżej :max adresów.', ['max' => self::MAX_ADD]));
        }
        // Adres sieci i rozgłoszeniowy pomijamy tylko wtedy, gdy to cała sieć bloku.
        $whole = $cidr === $pool->cidr && $size > 2;
        $first = $whole ? 1 : 0;
        $last = $whole ? $size - 2 : $size - 1;

        return array_map(fn (int $i) => IpMath::add(IpMath::networkAddress($cidr), $i), range($first, $last));
    }

    private function inBlock(IpPool $pool, string $value, string $field): string
    {
        if (IpMath::version($value) === null) {
            $this->fail($field, __(':address nie jest poprawnym adresem IP.', ['address' => $value]));
        }
        $address = $this->address($value, $pool->version, $field, __('Adres'));
        if (! IpMath::contains($pool->cidr, $address)) {
            $this->fail($field, __('Adres :address leży poza podsiecią :cidr.', ['address' => $address, 'cidr' => $pool->cidr]));
        }

        return $address;
    }

    /**
     * Resolvery z formularza: gotowy zestaw dla wersji IP bloku, własna lista
     * albo null (domyślne serwery panelu).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    public function resolvers(array $data, int $version): ?array
    {
        $preset = $data['dns_preset'] ?? null;
        if ($preset !== null && isset(self::DNS_PRESETS[$preset])) {
            return self::DNS_PRESETS[$preset][$version === 6 ? 2 : 1];
        }
        if ($preset === 'default') {
            return null;
        }

        return $this->nameservers($data['nameservers'] ?? null);
    }

    /** Nazwa gotowego zestawu, jeśli lista resolverów dokładnie mu odpowiada. */
    public static function presetFor(?array $nameservers): ?string
    {
        if (! $nameservers) {
            return 'default';
        }
        foreach (self::DNS_PRESETS as $key => [, $v4, $v6]) {
            if ($nameservers === $v4 || $nameservers === $v6) {
                return $key;
            }
        }

        return 'custom';
    }

    // --- walidacja sieciowa -------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $groupScope = ($data['scope'] ?? null) === 'group'
            || (empty($data['hypervisor_id']) && ! empty($data['hypervisor_group_id']));
        $type = $data['type'] ?? IpPool::TYPE_PUBLIC;

        try {
            $cidr = IpMath::normalizeCidr($data['cidr']);
        } catch (\InvalidArgumentException) {
            $this->fail('cidr', __('Podaj podsieć w notacji CIDR, np. 203.0.113.0/24 albo 2001:db8::/64.'));
        }

        $version = IpMath::parseCidr($cidr)['version'];
        $maxPrefix = $version === 4 ? 32 : 128;

        if ((int) $data['prefix'] > $maxPrefix) {
            $this->fail('prefix', __('Maska dla maszyny w IPv:version może mieć najwyżej /:maxprefix.', ['version' => $version, 'maxprefix' => $maxPrefix]));
        }

        $gateway = $this->address($data['gateway'], $version, 'gateway', 'Brama');

        if ($type === IpPool::TYPE_NAT) {
            if (! $this->isPrivate($cidr)) {
                $this->fail('cidr', $version === 4
                    ? __('Za NAT-em mogą stać tylko sieci prywatne: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 albo 100.64.0.0/10.')
                    : __('Za NAT-em IPv6 może stać tylko sieć ULA (fc00::/7), np. fd00:10::/64.'));
            }

            // Brama sieci NAT to adres węzła na mostku — musi leżeć w sieci.
            if (! IpMath::contains($cidr, $gateway)) {
                $this->fail('gateway', __('Brama puli NAT musi leżeć w podsieci :cidr — to adres węzła na mostku NAT.', ['cidr' => $cidr]));
            }
        }

        if (($data['fill'] ?? null) === 'range' && (empty($data['range_from']) || empty($data['range_to']))) {
            $this->fail('range_from', __('Przy wypełnieniu zakresem podaj adres początkowy i końcowy.'));
        }
        if (($data['fill'] ?? null) !== 'range' && ($data['fill'] ?? null) !== null) {
            $data['range_from'] = $data['range_to'] = null;
        }

        $rangeFrom = $this->rangeBound($data['range_from'] ?? null, $cidr, $version, 'range_from');
        $rangeTo = $this->rangeBound($data['range_to'] ?? null, $cidr, $version, 'range_to');

        if ($rangeFrom && $rangeTo && IpMath::compare($rangeFrom, $rangeTo) > 0) {
            $this->fail('range_to', __('Koniec zakresu nie może być przed jego początkiem.'));
        }

        if ($version === 4 && $rangeFrom && $rangeTo
            && IpMath::offset($rangeFrom, $rangeTo) >= 65536) {
            $this->fail('range_to', __('Pula IPv4 może liczyć najwyżej 65 536 adresów — podziel ją na mniejsze.'));
        }

        if ($version === 4 && ! $rangeFrom && ! $rangeTo && IpMath::size($cidr) > 65536) {
            $this->fail('cidr', __('Pula IPv4 może liczyć najwyżej 65 536 adresów (/16). Zawęź zakres.'));
        }

        $nat = $type === IpPool::TYPE_NAT;
        $portStart = $nat && $version === 4 ? ($data['nat_port_start'] ?? null) : null;
        $portsPerServer = $nat && $version === 4 ? ($data['nat_ports_per_server'] ?? null) : null;

        if (($portStart === null) !== ($portsPerServer === null)) {
            $this->fail('nat_port_start', __('Podaj pierwszy port i liczbę portów na maszynę — albo zostaw oba puste.'));
        }

        $publicAddress = null;
        if ($nat && ! empty($data['nat_public_address'])) {
            $publicAddress = $this->address($data['nat_public_address'], $version, 'nat_public_address', __('Adres wyjścia'));
        }

        return [
            'hypervisor_id' => $groupScope ? null : (int) $data['hypervisor_id'],
            'hypervisor_group_id' => $groupScope ? (int) $data['hypervisor_group_id'] : null,
            'name' => $data['name'],
            'type' => $type,
            'cidr' => $cidr,
            'version' => $version,
            'gateway' => $gateway,
            'prefix' => (int) $data['prefix'],
            'range_from' => $rangeFrom,
            'range_to' => $rangeTo,
            'nameservers' => $this->resolvers($data, $version),
            'nat_public_address' => $publicAddress,
            'nat_port_start' => $portStart !== null ? (int) $portStart : null,
            'nat_ports_per_server' => $portsPerServer !== null ? (int) $portsPerServer : null,
        ];
    }

    /** @throws ValidationException */
    private function assertNoConflicts(IpPool $pool): void
    {
        if ($pool->isNat() && $pool->nat_port_start && $pool->natPortSpan() === null) {
            $this->fail('nat_ports_per_server', sprintf(
                __('Bloki portów nie mieszczą się poniżej 65535: %d adresów × %d portów od portu %d. ')
                .__('Zmniejsz liczbę portów na maszynę albo zawęź zakres adresów.'),
                IpMath::offset($pool->firstAssignable(), $pool->lastAssignable()) + 1,
                $pool->nat_ports_per_server,
                $pool->nat_port_start,
            ));
        }

        if ($conflict = $pool->hostNetworkConflicts()[0] ?? null) {
            $this->fail('cidr', __('Podsieć nachodzi na sieć węzła :node (:interface: :network) — ruch maszyn szedłby złym interfejsem. Wybierz sieć prywatną nieużywaną na węźle, np. 10.77.0.0/24.', $conflict));
        }

        foreach ($this->neighbours($pool) as $other) {
            if ($other->version !== $pool->version || ! $this->overlaps($pool->cidr, $other->cidr)) {
                continue;
            }

            // Publiczne adresy są unikalne globalnie — nakładająca się pula
            // publiczna to prawie na pewno pomyłka w CIDR. Sieci NAT mogą się
            // powtarzać, ale nie na tym samym węźle (jeden mostek NAT).
            $this->fail('cidr', __('Podsieć nachodzi na pulę :name (:cidr, :scopelabel).', ['name' => $other->name, 'cidr' => $other->cidr, 'scopelabel' => $other->scopeLabel()]));
        }

        $span = $pool->natPortSpan();

        if ($span === null) {
            return;
        }

        foreach ($this->neighbours($pool) as $other) {
            $otherSpan = $other->natPortSpan();

            if ($otherSpan && $span['from'] <= $otherSpan['to'] && $otherSpan['from'] <= $span['to']) {
                $this->fail('nat_port_start', sprintf(
                    __('Porty %d–%d nachodzą na porty puli %s (%d–%d), która działa na tym samym węźle.'),
                    $span['from'], $span['to'], $other->name, $otherSpan['from'], $otherSpan['to'],
                ));
            }
        }
    }

    /**
     * Pule, z którymi nowa pula może się zderzyć: dla publicznej — wszystkie
     * publiczne; dla NAT — pule NAT, które mogą trafić na ten sam węzeł.
     *
     * @return Collection<int, IpPool>
     */
    private function neighbours(IpPool $pool): Collection
    {
        $query = IpPool::query()->with(['hypervisor', 'group'])->where('type', $pool->type)
            ->when($pool->exists, fn ($q) => $q->whereKeyNot($pool->id));

        if (! $pool->isNat()) {
            return $query->get();
        }

        if ($pool->isGroupPool()) {
            $nodeIds = Hypervisor::query()->where('hypervisor_group_id', $pool->hypervisor_group_id)->pluck('id');
            $query->where(fn ($q) => $q
                ->where('hypervisor_group_id', $pool->hypervisor_group_id)
                ->orWhereIn('hypervisor_id', $nodeIds));
        } else {
            $groupId = Hypervisor::query()->whereKey($pool->hypervisor_id)->value('hypervisor_group_id');
            $query->where(fn ($q) => $q
                ->where('hypervisor_id', $pool->hypervisor_id)
                ->when($groupId, fn ($q) => $q->orWhere('hypervisor_group_id', $groupId)));
        }

        return $query->get();
    }

    private function overlaps(string $a, string $b): bool
    {
        return IpMath::contains($a, IpMath::networkAddress($b))
            || IpMath::contains($b, IpMath::networkAddress($a));
    }

    private function isPrivate(string $cidr): bool
    {
        foreach (self::PRIVATE_NETWORKS as $private) {
            if (IpMath::contains($private, IpMath::networkAddress($cidr))
                && IpMath::contains($private, IpMath::lastAddress($cidr))) {
                return true;
            }
        }

        return false;
    }

    private function address(string $value, int $version, string $field, string $label): string
    {
        if (IpMath::version($value) !== $version) {
            $this->fail($field, __(':label musi być adresem IPv:version, tak jak podsieć puli.', ['label' => $label, 'version' => $version]));
        }

        return IpMath::normalize($value);
    }

    private function rangeBound(?string $value, string $cidr, int $version, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $address = $this->address($value, $version, $field, 'Granica zakresu');

        if (! IpMath::contains($cidr, $address)) {
            $this->fail($field, __('Adres :address leży poza podsiecią :cidr.', ['address' => $address, 'cidr' => $cidr]));
        }

        return $address;
    }

    /** @return list<string>|null */
    private function nameservers(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $list = is_array($value) ? $value : explode(',', (string) $value);
        $list = array_values(array_filter(array_map('trim', $list), fn ($ns) => $ns !== ''));

        if (count($list) > 4) {
            $this->fail('nameservers', __('Podaj najwyżej 4 serwery DNS.'));
        }

        foreach ($list as $ns) {
            if (IpMath::version($ns) === null) {
                $this->fail('nameservers', __('Serwer DNS :address nie jest poprawnym adresem IP.', ['address' => $ns]));
            }
        }

        return $list ?: null;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
