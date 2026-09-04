<?php

namespace App\Domain\Provisioning;

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
 */
class IpAllocator
{
    /**
     * @return Collection<int, IpAddress>
     *
     * @throws NoAddressesException
     */
    public function allocate(Server $server, Hypervisor $hypervisor, int $count = 1): Collection
    {
        return DB::transaction(function () use ($server, $hypervisor, $count) {
            $addresses = IpAddress::query()
                ->where('hypervisor_id', $hypervisor->id)
                ->assignable()
                ->orderBy('id')
                ->lockForUpdate()
                ->limit($count)
                ->get();

            if ($addresses->count() < $count) {
                throw new NoAddressesException(
                    "Pula adresów hypervisora {$hypervisor->name} jest wyczerpana: "
                    ."potrzeba {$count}, wolnych jest {$addresses->count()}."
                );
            }

            $hasPrimary = $server->ipAddresses()->where('is_primary', true)->exists();

            foreach ($addresses as $index => $address) {
                $address->forceFill([
                    'server_id' => $server->id,
                    'is_primary' => ! $hasPrimary && $index === 0,
                    'assigned_at' => now(),
                ])->save();
            }

            return $addresses;
        });
    }

    /** Zwalnia adresy maszyny z powrotem do puli, czyszcząc rDNS po poprzednim właścicielu. */
    public function releaseAll(Server $server): void
    {
        DB::transaction(function () use ($server) {
            IpAddress::query()
                ->where('server_id', $server->id)
                ->lockForUpdate()
                ->get()
                ->each(function (IpAddress $address) {
                    $address->forceFill([
                        'server_id' => null,
                        'is_primary' => false,
                        'assigned_at' => null,
                        'rdns' => null,
                    ])->save();
                });
        });
    }

    public function release(IpAddress $address): void
    {
        $address->forceFill([
            'server_id' => null,
            'is_primary' => false,
            'assigned_at' => null,
            'rdns' => null,
        ])->save();
    }

    /**
     * Rozwija pulę CIDR na pojedyncze adresy.
     *
     * Adres sieci, rozgłoszeniowy i brama trafiają do bazy jako zarezerwowane —
     * są widoczne w panelu (administrator widzi, że pula jest kompletna), ale
     * nie da się ich przydzielić klientowi.
     *
     * @return int liczba dodanych adresów
     */
    public function importPool(IpPool $pool, ?string $rangeFrom = null, ?string $rangeTo = null): int
    {
        if ($pool->version !== 4) {
            throw new \InvalidArgumentException(
                'Import puli IPv6 wymaga przydziału prefiksów, nie pojedynczych adresów — '
                .'funkcja planowana wraz z pełnym wsparciem IPv6.'
            );
        }

        [$network, $bits] = explode('/', $pool->cidr);
        $networkLong = ip2long($network);
        $size = 2 ** (32 - (int) $bits);

        $first = $rangeFrom ? ip2long($rangeFrom) : $networkLong + 1;
        $last = $rangeTo ? ip2long($rangeTo) : $networkLong + $size - 2;

        $gatewayLong = ip2long($pool->gateway);
        $rows = [];
        $now = now();

        for ($long = $first; $long <= $last; $long++) {
            $rows[] = [
                'ip_pool_id' => $pool->id,
                'hypervisor_id' => $pool->hypervisor_id,
                'address' => long2ip($long),
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
}
