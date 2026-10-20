<?php

namespace App\Domain\Provisioning;

use App\Domain\Billing\Billing;
use App\Enums\Virtualization;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VpsPackage;

/**
 * Czego brakuje, żeby klient mógł zamówić maszynę — podpowiedzi dla personelu
 * w sklepie i w formularzu zamawiania (klient widzi tylko „brak oferty").
 */
class OrderReadiness
{
    public function __construct(private readonly TemplateCatalog $catalog) {}

    /** @return list<array{message: string, url: ?string}> */
    public function issues(): array
    {
        $issues = [];
        $add = function (string $message, ?string $route = null) use (&$issues): void {
            $issues[] = ['message' => $message, 'url' => $route ? route($route) : null];
        };

        if (Billing::enabled()) {
            $categories = ProductCategory::query()->where('is_active', true)->with(['products' => fn ($q) => $q->where('is_active', true)->with('prices', 'package', 'plan', 'providerAccount')])->get();
            if ($categories->isEmpty()) {
                $add(__('Sklep nie ma żadnej aktywnej kategorii. Produkty są widoczne tylko w kategoriach.'), 'panel.admin.billing.catalog');
            } elseif ($categories->every(fn ($c) => $c->products->isEmpty())) {
                $add(__('Aktywne kategorie nie mają aktywnych produktów.'), 'panel.admin.billing.catalog');
            }
            foreach ($categories->flatMap->products as $product) {
                if (! $product->deliverable()) {
                    $add(__('Produkt „:name” jest ukryty: brak aktywnego pakietu, planu albo konta dostawcy.', ['name' => $product->name]), 'panel.admin.billing.catalog');
                } elseif ($product->priceMap() === []) {
                    $add(__('Produkt „:name” jest ukryty: nie ma żadnej ceny.', ['name' => $product->name]), 'panel.admin.billing.catalog');
                } elseif ($product->remaining() === 0) {
                    $add(__('Produkt „:name” jest wyprzedany (limit sztuk).', ['name' => $product->name]), 'panel.admin.billing.catalog');
                }
            }
            $sellsVps = $categories->flatMap->products->contains(fn (Product $p) => $p->type === Product::TYPE_VPS);
        } else {
            if (! VpsPackage::query()->active()->exists()) {
                $add(__('Nie ma aktywnego pakietu zasobów. Bez niego klient nie ma czego wybrać.'), 'panel.admin.packages');
            }
            $sellsVps = true;
        }

        if ($sellsVps) {
            $available = Hypervisor::query()->available()->get();
            if ($available->isEmpty()) {
                $offline = Hypervisor::query()->whereNotNull('enrolled_at')->count();
                $add($offline > 0
                    ? __('Żaden węzeł nie przyjmuje maszyn: wszystkie są offline, bez kontaktu albo mają wyłączone przyjmowanie nowych maszyn.')
                    : __('Nie ma zarejestrowanego węzła. Dodaj hypervisor KVM albo host kontenerów LXC.'), 'panel.admin.hypervisors');
            } else {
                $types = $available->pluck('virtualization')->map(fn ($t) => $t instanceof Virtualization ? $t->value : $t)->unique()->values()->all();
                foreach ($types as $type) {
                    if ($this->catalog->choices([$type])->isEmpty()) {
                        $add(__('Węzły :type działają, ale nie ma dla nich systemu do zamówienia: szablon musi być aktywny, mieć cloud-init, a obraz KVM musi być pobrany na węzeł.', ['type' => strtoupper($type)]), 'panel.admin.templates');
                    }
                }
                $freeV4 = IpAddress::query()->where('version', 4)->whereNull('server_id')->where('is_reserved', false)->count();
                if ($freeV4 === 0) {
                    $add(__('Nie ma wolnych adresów IPv4 w pulach. Nowa maszyna nie dostanie adresu.'), 'panel.admin.ip-pools');
                }
            }
        }

        return $issues;
    }
}
