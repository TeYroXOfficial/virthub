<?php

namespace App\Models;

use App\Domain\Billing\Cycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pozycja sklepu: pakiet VPS albo plan aplikacji z cenami dla cykli. */
class Product extends Model
{
    public const TYPE_VPS = 'vps';

    public const TYPE_APP = 'app';

    /** VPS u dostawcy zewnętrznego (reselling przez addon). */
    public const TYPE_EXTERNAL = 'external';

    protected $fillable = [
        'product_category_id', 'name', 'description', 'type', 'vps_package_id', 'app_plan_id', 'provider_account_id', 'external_config',
        'app_egg_ids', 'hypervisor_group_ids', 'setup_fee', 'stock', 'per_user_limit', 'is_active', 'sort_order',
        'keepalive_interval', 'keepalive_window', 'keepalive_delete_after',
        'addon_ip_hour', 'addon_pci_hour', 'resource_options',
    ];

    /** Zasoby, które klient może dobrać ponad pakiet: klucz => [jednostka, pole pakietu, mnożnik]. */
    public const RESOURCE_OPTIONS = ['vcpu' => ['vCPU', 'vcpu', 1], 'ram_gb' => ['GB RAM', 'ram_mb', 1024], 'disk_gb' => ['GB', 'disk_gb', 1]];

    protected $attributes = ['is_active' => true, 'setup_fee' => 0, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'app_egg_ids' => 'array',
            'external_config' => 'array',
            'hypervisor_group_ids' => 'array',
            'setup_fee' => 'integer',
            'stock' => 'integer',
            'per_user_limit' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'addon_ip_hour' => 'integer',
            'addon_pci_hour' => 'integer',
            'resource_options' => 'array',
        ];
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /** @return BelongsTo<VpsPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(VpsPackage::class, 'vps_package_id');
    }

    /** @return BelongsTo<AppPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(AppPlan::class, 'app_plan_id');
    }

    /** @return BelongsTo<ProviderAccount, $this> */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    /**
     * Systemy do wyboru w produkcie zewnętrznym.
     *
     * @return list<array{id:string, name:string}>
     */
    public function externalImages(): array
    {
        return array_values(array_filter((array) ($this->external_config['images'] ?? []), fn ($i) => is_array($i) && isset($i['id'], $i['name'])));
    }

    /**
     * Zmienne limity pakietu: ile jednostek klient może dobrać i za ile (netto/h).
     *
     * @return array<string, array{max: int, price_hour: int}>
     */
    public function resourceOptions(): array
    {
        $out = [];
        foreach (self::RESOURCE_OPTIONS as $key => $_) {
            $o = (array) ($this->resource_options[$key] ?? []);
            if ((int) ($o['max'] ?? 0) > 0) {
                $out[$key] = ['max' => (int) $o['max'], 'price_hour' => max(0, (int) ($o['price_hour'] ?? 0))];
            }
        }

        return $this->type === self::TYPE_VPS ? $out : [];
    }

    /** Dopłata netto za dobrane zasoby w danym okresie (grosze). */
    public function extrasPrice(array $extra, string $cycle): int
    {
        $hourly = 0;
        foreach ($this->resourceOptions() as $key => $o) {
            $hourly += min($o['max'], max(0, (int) ($extra[$key] ?? 0))) * $o['price_hour'];
        }

        return $hourly * Cycle::hours($cycle);
    }

    /** Pakiet powiększony o dobrane zasoby (niezapisywany — do utworzenia maszyny). */
    public function packageWith(array $extra): ?VpsPackage
    {
        $base = $this->package;
        $options = $this->resourceOptions();
        if ($base === null || $options === [] || array_sum(array_map('intval', $extra)) === 0) {
            return $base;
        }
        $package = $base->replicate();
        foreach ($options as $key => $o) {
            [, $field, $multiplier] = self::RESOURCE_OPTIONS[$key];
            $package->{$field} = (int) $base->{$field} + min($o['max'], max(0, (int) ($extra[$key] ?? 0))) * $multiplier;
        }
        $package->id = $base->id;

        return $package;
    }

    /** @return HasMany<ProductPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /** @return HasMany<BillingService, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(BillingService::class);
    }

    /** @param  Builder<Product>  $query */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_active', true)->whereHas('category', fn ($q) => $q->where('is_active', true));
    }

    /**
     * Cykle w sprzedaży z cenami, w kolejności cykli: ['monthly' => 125000, …].
     * Cykl jest oferowany, gdy produkt go ma (cena 0 = za darmo) i kategoria
     * na niego pozwala.
     *
     * @return array<string, int>
     */
    public function priceMap(): array
    {
        $category = $this->category;

        return array_filter($this->configuredPrices(), fn ($amount, $cycle) => $category === null || $category->allowsCycle($cycle), ARRAY_FILTER_USE_BOTH);
    }

    /** Wszystkie cykle ustawione w produkcie — także te, których kategoria teraz nie dopuszcza. @return array<string, int> */
    public function configuredPrices(): array
    {
        $map = $this->prices->filter(fn (ProductPrice $p) => Cycle::valid($p->cycle))
            ->mapWithKeys(fn (ProductPrice $p) => [$p->cycle => (int) $p->amount])->all();

        return Cycle::sort($map);
    }

    /** Czy okres się odnawia (false = usługa jednorazowa na ten czas). */
    public function renews(string $cycle): bool
    {
        return (bool) ($this->prices->firstWhere('cycle', $cycle)?->renews ?? true);
    }

    /** Wszystkie oferowane cykle są darmowe. */
    public function isFree(): bool
    {
        $prices = $this->priceMap();

        return $prices !== [] && max($prices) === 0 && $this->setup_fee === 0;
    }

    public function priceFor(string $cycle): ?int
    {
        return $this->priceMap()[$cycle] ?? null;
    }

    /** Usługa, którą produkt tworzy, istnieje i jest aktywna. */
    public function deliverable(): bool
    {
        return match ($this->type) {
            self::TYPE_VPS => $this->package !== null && $this->package->is_active,
            self::TYPE_EXTERNAL => $this->providerAccount !== null && $this->providerAccount->is_active
                && $this->providerAccount->driverAvailable() && $this->externalImages() !== [],
            default => $this->plan !== null && $this->plan->is_active,
        };
    }

    /** Ile sztuk jeszcze można sprzedać (null = bez limitu). */
    public function remaining(): ?int
    {
        if ($this->stock === null) {
            return null;
        }
        $used = $this->services()->whereIn('status', [BillingService::STATUS_PENDING, BillingService::STATUS_ACTIVE, BillingService::STATUS_SUSPENDED])->count();

        return max(0, $this->stock - $used);
    }

    /** Lokalizacje do wyboru: publiczne grupy, ograniczone listą produktu. @return Collection<int, HypervisorGroup> */
    public function locations(): Collection
    {
        if ($this->type === self::TYPE_EXTERNAL) {
            return new Collection; // lokalizacja jest częścią konfiguracji produktu u dostawcy
        }
        $query = HypervisorGroup::query()->where('is_public', true)->where('accepts_new_servers', true)->ordered();
        if ($this->hypervisor_group_ids) {
            $query->whereIn('id', array_map('intval', $this->hypervisor_group_ids));
        }
        $query->whereHas('hypervisors', fn ($q) => $this->type === self::TYPE_APP
            ? $q->where('apps_enabled', true)->where('status', Hypervisor::STATUS_ONLINE)
            : $q->available());

        return $query->get();
    }

    /** Szablony aplikacji do wyboru. @return Collection<int, AppEgg> */
    public function eggs(): Collection
    {
        $query = AppEgg::query()->active()->orderBy('category')->orderBy('name');
        if ($this->app_egg_ids) {
            $query->whereIn('id', array_map('intval', $this->app_egg_ids));
        }

        return $query->get();
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_VPS, self::TYPE_EXTERNAL => __('Serwer VPS'),
            default => __('Aplikacja'),
        };
    }
}
