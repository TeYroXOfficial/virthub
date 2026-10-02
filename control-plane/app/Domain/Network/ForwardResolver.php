<?php

namespace App\Domain\Network;

/** Rekordy A/AAAA nazwy — do sprawdzenia, czy rDNS wskazuje na nazwę, która wraca do tego adresu. */
class ForwardResolver
{
    /** @return list<string> */
    public function addresses(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $r) => isset($r['ip']) ? $r['ip'] : (isset($r['ipv6']) ? IpMath::normalize($r['ipv6']) : null),
            $records,
        )));
    }
}
