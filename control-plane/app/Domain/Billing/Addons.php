<?php

namespace App\Domain\Billing;

use App\Models\Product;
use App\Models\Server;

/**
 * Dodatki maszyny rozliczane godzinowo: adresy IP ponad pulę pakietu i
 * przypięte urządzenia PCI (GPU). Liczone przy każdym naliczeniu — dodanie
 * albo zdjęcie adresu zmienia kwotę od następnego okresu.
 */
final class Addons
{
    /** @return array{ips: int, pci: int} */
    public static function count(Server $server): array
    {
        $package = $server->package;
        $v4 = $server->ipAddresses()->where('version', 4)->count();
        $v6 = $server->ipAddresses()->where('version', 6)->count();
        $extraIps = max(0, $v4 - (int) ($package?->ip_count ?? 0)) + max(0, $v6 - (int) ($package?->ipv6_count ?? 0));

        return ['ips' => $extraIps, 'pci' => $server->pciAssets()->count()];
    }

    /** Koszt netto godziny dodatków maszyny w produkcie (grosze). */
    public static function hourly(Product $product, Server $server): int
    {
        if ((int) $product->addon_ip_hour + (int) $product->addon_pci_hour === 0) {
            return 0;
        }
        $count = self::count($server);

        return $count['ips'] * (int) $product->addon_ip_hour + $count['pci'] * (int) $product->addon_pci_hour;
    }

    /** „2 × IP, 1 × GPU/PCI” — do opisu operacji w portfelu. */
    public static function describe(Server $server): string
    {
        $count = self::count($server);
        $parts = [];
        if ($count['ips'] > 0) {
            $parts[] = $count['ips'].' × IP';
        }
        if ($count['pci'] > 0) {
            $parts[] = $count['pci'].' × PCI';
        }

        return implode(', ', $parts);
    }
}
