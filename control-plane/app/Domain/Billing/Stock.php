<?php

namespace App\Domain\Billing;

use App\Domain\Apps\AppProvisioner;
use App\Domain\Provisioning\HypervisorSelector;
use App\Domain\Provisioning\IpAllocator;
use App\Domain\Provisioning\ServerProvisioner;
use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
use App\Models\IpPool;
use App\Models\OsTemplate;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Czy produkt da się teraz uruchomić: limit sztuk, a dla maszyn i aplikacji
 * także wolne miejsce na węzłach (zasoby, adresy IP, magazyn). Sklep pokazuje
 * produkt bez miejsca jako wyprzedany, zamiast przyjmować zamówienie, które
 * i tak skończy się zwrotem pieniędzy.
 *
 * Wyniki są zapamiętywane w obiekcie — bierz świeży z kontenera na każde
 * żądanie, nie wstrzykuj do konstruktora kontrolera.
 */
class Stock
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(
        private readonly HypervisorSelector $selector,
        private readonly IpAllocator $ips,
        private readonly AppProvisioner $apps,
    ) {}

    /** Produkt można zamówić w którejkolwiek lokalizacji (albo bez wyboru lokalizacji). */
    public function inStock(Product $product): bool
    {
        if ($product->remaining() === 0) {
            return false;
        }
        if ($product->type === Product::TYPE_EXTERNAL) {
            return true; // miejsce sprawdza dostawca
        }
        if ($product->hypervisor_group_ids) {
            return $product->locations()->contains(fn (HypervisorGroup $g) => $this->fits($product, $g));
        }

        return $this->fits($product);
    }

    /** Lokalizacje produktu z wolnym miejscem. @return Collection<int, HypervisorGroup> */
    public function locations(Product $product): Collection
    {
        return $product->locations()->filter(fn (HypervisorGroup $g) => $this->fits($product, $g))->values();
    }

    /** Czy produkt zmieści się na którymś węźle (w lokalizacji, gdy podana). */
    public function fits(Product $product, ?HypervisorGroup $location = null): bool
    {
        return $this->cache[$product->id.':'.($location?->id ?? '*')] ??= match ($product->type) {
            Product::TYPE_VPS => $this->vpsFits($product, $location),
            Product::TYPE_EXTERNAL => true,
            default => $this->appFits($product, $location),
        };
    }

    private function vpsFits(Product $product, ?HypervisorGroup $location): bool
    {
        $package = $product->package;
        if ($package === null) {
            return false;
        }
        $networkType = $package->network_type ?: IpPool::TYPE_PUBLIC;

        // Wystarczy, że zmieści się z którymkolwiek systemem z oferty.
        return $product->orderableTemplates()
            ->unique(fn (OsTemplate $t) => $t->virtualization->value.'/'.($t->arch ?: 'x86_64'))
            ->contains(function (OsTemplate $template) use ($package, $location, $networkType) {
                $datastore = $package->datastore_id && ! $template->isContainer() ? $package->datastore : null;
                if ($datastore !== null && ! $datastore->is_active) {
                    return false;
                }

                return $this->selector->select(
                    $package->vcpu, $package->ram_mb, $package->totalDiskGb(), $template->virtualization,
                    fn (Hypervisor $h) => ($location === null || $h->hypervisor_group_id === $location->id)
                        && $h->arch() === ($template->arch ?: 'x86_64')
                        && ServerProvisioner::nodeHasDatastore($h, $datastore)
                        && $this->ips->hasCapacity($h, $networkType, $package->ip_count, (int) $package->ipv6_count),
                ) !== null;
            });
    }

    private function appFits(Product $product, ?HypervisorGroup $location): bool
    {
        $plan = $product->plan;
        if ($plan === null) {
            return false;
        }

        return Hypervisor::query()
            ->when($location, fn ($q) => $q->where('hypervisor_group_id', $location->id))
            ->where('apps_enabled', true)
            ->where('status', Hypervisor::STATUS_ONLINE)
            ->get()
            ->contains(fn (Hypervisor $node) => $node->acceptsApps() && $this->apps->fits($node, $plan));
    }
}
