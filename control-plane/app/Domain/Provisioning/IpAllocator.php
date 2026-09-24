<?php

namespace App\Domain\Provisioning;

use App\Domain\Network\IpMath;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Przydzielanie adresów z pul. Adres jest zasobem współdzielonym i niepodzielnym —
 * dwie maszyny z tym samym IP to natychmiastowa awaria u obu klientów, więc
 * każdy przydział idzie pod blokadą wiersza.
 *
 * Maszyna korzysta z pul swojego węzła i z pul jego grupy. Pule węzła mają
 * pierwszeństwo: pula grupy jest zasobem wspólnym i nie powinna się kończyć
 * przez węzeł, który ma własne adresy.
 */
class IpAllocator
{
    /** Ile kolejnych zajętych adresów IPv6 pomijamy, zanim uznamy pulę za pełną. */
    private const IPV6_SCAN_LIMIT = 1000;

    /**
     * @param  string  $type  rodzaj adresów (IpPool::TYPE_*) — wszystkie adresy
     *                        maszyny są jednego rodzaju, bo ma jedną kartę sieciową
     * @return Collection<int, IpAddress>
     *
     * @throws NoAddressesException
     */
    public function allocate(
        Server $server,
        Hypervisor $hypervisor,
        int $count = 1,
        int $countV6 = 0,
        string $type = IpPool::TYPE_PUBLIC,
    ): Collection {
        return DB::transaction(function () use ($server, $hypervisor, $count, $countV6, $type) {
            $addresses = $this->takeV4($hypervisor, $type, $count)
                ->concat($this->takeV6($hypervisor, $type, $countV6));

            $hasPrimary = $server->ipAddresses()->where('is_primary', true)->exists();

            foreach ($addresses->values() as $index => $address) {
                $address->forceFill([
                    'server_id' => $server->id,
                    // Adres z puli grupy należy do węzła tak długo, jak długo
                    // stoi na nim maszyna.
                    'hypervisor_id' => $hypervisor->id,
                    'is_primary' => ! $hasPrimary && $index === 0,
                    'assigned_at' => now(),
                ])->save();
            }

            return $addresses->values();
        });
    }

    /**
     * Czy węzeł ma dość wolnych adresów — do doboru węzła, zanim zarezerwujemy
     * na nim zasoby. IPv6 przydzielamy leniwie, więc wystarczy, że pula jest.
     */
    public function hasCapacity(Hypervisor $hypervisor, string $type, int $count, int $countV6 = 0): bool
    {
        if ($count > 0) {
            $free = IpAddress::query()
                ->whereIn('ip_pool_id', $this->usablePools($hypervisor, $type, 4)->modelKeys())
                ->assignable()
                ->count();

            if ($free < $count) {
                return false;
            }
        }

        return $countV6 === 0 || $this->usablePools($hypervisor, $type, 6)->isNotEmpty();
    }

    /** Zwalnia adresy maszyny z powrotem do puli, czyszcząc rDNS po poprzednim właścicielu. */
    public function releaseAll(Server $server): void
    {
        DB::transaction(function () use ($server) {
            IpAddress::query()
                ->with('pool')
                ->where('server_id', $server->id)
                ->lockForUpdate()
                ->get()
                ->each(fn (IpAddress $address) => $this->release($address));
        });
    }

    public function release(IpAddress $address): void
    {
        $address->forceFill([
            'server_id' => null,
            // Adres puli grupy wraca do grupy, adres puli węzła zostaje przy węźle.
            'hypervisor_id' => $address->pool?->hypervisor_id,
            'is_primary' => false,
            'assigned_at' => null,
            'rdns' => null,
        ])->save();
    }

    /**
     * Rozwija pulę IPv4 na pojedyncze adresy.
     *
     * Adres sieci, rozgłoszeniowy i brama trafiają do bazy jako zarezerwowane —
     * są widoczne w panelu (administrator widzi, że pula jest kompletna), ale
     * nie da się ich przydzielić klientowi.
     *
     * Pul IPv6 nie rozwijamy — /64 to 2^64 adresów. Adresy powstają przy
     * przydziale (patrz takeV6), więc import zwraca dla nich 0.
     *
     * @return int liczba dodanych adresów
     */
    public function importPool(IpPool $pool, ?string $rangeFrom = null, ?string $rangeTo = null): int
    {
        if ($pool->version !== 4) {
            return 0;
        }

        [$network, $bits] = explode('/', $pool->cidr);
        $networkLong = ip2long($network);
        $size = 2 ** (32 - (int) $bits);

        $rangeFrom ??= $pool->range_from;
        $rangeTo ??= $pool->range_to;

        $first = $rangeFrom ? ip2long($rangeFrom) : $networkLong + ($size > 2 ? 1 : 0);
        $last = $rangeTo ? ip2long($rangeTo) : $networkLong + $size - ($size > 2 ? 2 : 1);

        $gatewayLong = ip2long($pool->gateway);
        $rows = [];
        $now = now();

        for ($long = $first; $long <= $last; $long++) {
            $rows[] = [
                'ip_pool_id' => $pool->id,
                'hypervisor_id' => $pool->hypervisor_id,
                'address' => long2ip($long),
                'scope_key' => $pool->scopeKey(),
                'version' => 4,
                'is_reserved' => $long === $gatewayLong,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            // insertOrIgnore: ponowny import tej samej puli nie może wywrócić
            // się na unikalności ani zduplikować adresów.
            $inserted += IpAddress::query()->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    // --- pomocnicze ---------------------------------------------------------

    /** @return \Illuminate\Database\Eloquent\Collection<int, IpPool> */
    private function usablePools(Hypervisor $hypervisor, string $type, int $version)
    {
        return IpPool::query()
            ->usableBy($hypervisor)
            ->where('type', $type)
            ->where('version', $version)
            // Najpierw pule węzła, potem pule grupy.
            ->orderByRaw('CASE WHEN hypervisor_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, IpAddress> */
    private function takeV4(Hypervisor $hypervisor, string $type, int $count): Collection
    {
        $picked = collect();

        foreach ($this->usablePools($hypervisor, $type, 4) as $pool) {
            $need = $count - $picked->count();

            if ($need <= 0) {
                break;
            }

            $picked = $picked->concat(
                $pool->addresses()->assignable()->orderBy('id')->lockForUpdate()->limit($need)->get()
            );
        }

        if ($picked->count() < $count) {
            throw new NoAddressesException(sprintf(
                'Pule %s IPv4 węzła %s są wyczerpane: potrzeba %d, wolnych jest %d.',
                $type === IpPool::TYPE_NAT ? 'NAT' : 'publiczne',
                $hypervisor->name,
                $count,
                $picked->count(),
            ));
        }

        return $picked;
    }

    /**
     * IPv6: najpierw adresy zwolnione przez poprzednie maszyny, potem kolejne
     * od kursora puli. Kursor przesuwamy pod blokadą wiersza puli, więc dwa
     * równoległe zamówienia nie wygenerują tego samego adresu.
     *
     * @return Collection<int, IpAddress>
     */
    private function takeV6(Hypervisor $hypervisor, string $type, int $count): Collection
    {
        $picked = collect();

        if ($count <= 0) {
            return $picked;
        }

        foreach ($this->usablePools($hypervisor, $type, 6) as $pool) {
            $need = $count - $picked->count();

            if ($need <= 0) {
                break;
            }

            $pool = IpPool::query()->lockForUpdate()->findOrFail($pool->id);

            $reused = $pool->addresses()->assignable()->orderBy('id')->lockForUpdate()->limit($need)->get();
            $picked = $picked->concat($reused)->concat($this->generateV6($pool, $need - $reused->count()));
        }

        if ($picked->count() < $count) {
            throw new NoAddressesException(sprintf(
                'Brak wolnych adresów IPv6 (%s) dla węzła %s: potrzeba %d, dostępnych %d.',
                $type === IpPool::TYPE_NAT ? 'NAT' : 'publicznych',
                $hypervisor->name,
                $count,
                $picked->count(),
            ));
        }

        return $picked;
    }

    /** @return Collection<int, IpAddress> */
    private function generateV6(IpPool $pool, int $count): Collection
    {
        $created = collect();

        if ($count <= 0) {
            return $created;
        }

        $first = $pool->firstAssignable();
        $last = $pool->lastAssignable();
        $offset = $pool->next_offset;
        $skipped = 0;

        while ($created->count() < $count && $skipped <= self::IPV6_SCAN_LIMIT) {
            try {
                $candidate = IpMath::add($first, $offset);
            } catch (\InvalidArgumentException) {
                break;
            }

            if (IpMath::compare($candidate, $last) > 0) {
                break;
            }

            $offset++;

            $taken = IpMath::compare($candidate, $pool->gateway) === 0
                || IpAddress::query()
                    ->where('scope_key', $pool->scopeKey())
                    ->where('address', $candidate)
                    ->exists();

            if ($taken) {
                $skipped++;

                continue;
            }

            $created->push(IpAddress::create([
                'ip_pool_id' => $pool->id,
                'hypervisor_id' => $pool->hypervisor_id,
                'address' => $candidate,
                'scope_key' => $pool->scopeKey(),
                'version' => 6,
            ]));
        }

        $pool->forceFill(['next_offset' => $offset])->save();

        return $created;
    }
}
