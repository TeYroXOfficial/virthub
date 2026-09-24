<?php

namespace App\Domain\Network;

use App\Domain\Provisioning\IpAllocator;
use App\Models\AuditLog;
use App\Models\Hypervisor;
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

        return DB::transaction(function () use ($attributes) {
            $pool = new IpPool($attributes);
            $this->assertNoConflicts($pool);
            $pool->save();

            $imported = $this->allocator->importPool($pool);

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
                'pool' => "Z puli {$pool->name} korzystają maszyny. Zwolnij adresy albo usuń maszyny, "
                    .'zanim usuniesz pulę.',
            ]);
        }

        AuditLog::record('ip_pool.deleted', $pool, ['cidr' => $pool->cidr]);
        $pool->delete();
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
            $this->fail('cidr', 'Podaj podsieć w notacji CIDR, np. 203.0.113.0/24 albo 2001:db8::/64.');
        }

        $version = IpMath::parseCidr($cidr)['version'];
        $maxPrefix = $version === 4 ? 32 : 128;

        if ((int) $data['prefix'] > $maxPrefix) {
            $this->fail('prefix', "Maska dla maszyny w IPv{$version} może mieć najwyżej /{$maxPrefix}.");
        }

        $gateway = $this->address($data['gateway'], $version, 'gateway', 'Brama');

        if ($type === IpPool::TYPE_NAT) {
            if (! $this->isPrivate($cidr)) {
                $this->fail('cidr', $version === 4
                    ? 'Za NAT-em mogą stać tylko sieci prywatne: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 albo 100.64.0.0/10.'
                    : 'Za NAT-em IPv6 może stać tylko sieć ULA (fc00::/7), np. fd00:10::/64.');
            }

            // Brama sieci NAT to adres węzła na mostku — musi leżeć w sieci.
            if (! IpMath::contains($cidr, $gateway)) {
                $this->fail('gateway', "Brama puli NAT musi leżeć w podsieci {$cidr} — to adres węzła na mostku NAT.");
            }
        }

        $rangeFrom = $this->rangeBound($data['range_from'] ?? null, $cidr, $version, 'range_from');
        $rangeTo = $this->rangeBound($data['range_to'] ?? null, $cidr, $version, 'range_to');

        if ($rangeFrom && $rangeTo && IpMath::compare($rangeFrom, $rangeTo) > 0) {
            $this->fail('range_to', 'Koniec zakresu nie może być przed jego początkiem.');
        }

        if ($version === 4 && $rangeFrom && $rangeTo
            && IpMath::offset($rangeFrom, $rangeTo) >= 65536) {
            $this->fail('range_to', 'Pula IPv4 może liczyć najwyżej 65 536 adresów — podziel ją na mniejsze.');
        }

        if ($version === 4 && ! $rangeFrom && ! $rangeTo && IpMath::size($cidr) > 65536) {
            $this->fail('cidr', 'Pula IPv4 może liczyć najwyżej 65 536 adresów (/16). Zawęź zakres.');
        }

        $nat = $type === IpPool::TYPE_NAT;
        $portStart = $nat && $version === 4 ? ($data['nat_port_start'] ?? null) : null;
        $portsPerServer = $nat && $version === 4 ? ($data['nat_ports_per_server'] ?? null) : null;

        if (($portStart === null) !== ($portsPerServer === null)) {
            $this->fail('nat_port_start', 'Podaj pierwszy port i liczbę portów na maszynę — albo zostaw oba puste.');
        }

        $publicAddress = null;
        if ($nat && ! empty($data['nat_public_address'])) {
            $publicAddress = $this->address($data['nat_public_address'], $version, 'nat_public_address', 'Adres wyjścia');
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
            'nameservers' => $this->nameservers($data['nameservers'] ?? null),
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
                'Bloki portów nie mieszczą się poniżej 65535: %d adresów × %d portów od portu %d. '
                .'Zmniejsz liczbę portów na maszynę albo zawęź zakres adresów.',
                IpMath::offset($pool->firstAssignable(), $pool->lastAssignable()) + 1,
                $pool->nat_ports_per_server,
                $pool->nat_port_start,
            ));
        }

        foreach ($this->neighbours($pool) as $other) {
            if ($other->version !== $pool->version || ! $this->overlaps($pool->cidr, $other->cidr)) {
                continue;
            }

            // Publiczne adresy są unikalne globalnie — nakładająca się pula
            // publiczna to prawie na pewno pomyłka w CIDR. Sieci NAT mogą się
            // powtarzać, ale nie na tym samym węźle (jeden mostek NAT).
            $this->fail('cidr', "Podsieć nachodzi na pulę {$other->name} ({$other->cidr}, {$other->scopeLabel()}).");
        }

        $span = $pool->natPortSpan();

        if ($span === null) {
            return;
        }

        foreach ($this->neighbours($pool) as $other) {
            $otherSpan = $other->natPortSpan();

            if ($otherSpan && $span['from'] <= $otherSpan['to'] && $otherSpan['from'] <= $span['to']) {
                $this->fail('nat_port_start', sprintf(
                    'Porty %d–%d nachodzą na porty puli %s (%d–%d), która działa na tym samym węźle.',
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
        $query = IpPool::query()->with(['hypervisor', 'group'])->where('type', $pool->type);

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
            $this->fail($field, "{$label} musi być adresem IPv{$version}, tak jak podsieć puli.");
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
            $this->fail($field, "Adres {$address} leży poza podsiecią {$cidr}.");
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
            $this->fail('nameservers', 'Podaj najwyżej 4 serwery DNS.');
        }

        foreach ($list as $ns) {
            if (IpMath::version($ns) === null) {
                $this->fail('nameservers', "Serwer DNS {$ns} nie jest poprawnym adresem IP.");
            }
        }

        return $list ?: null;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
