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

    protected $fillable = [
        'product_category_id', 'name', 'description', 'type', 'vps_package_id', 'app_plan_id',
        'app_egg_ids', 'hypervisor_group_ids', 'setup_fee', 'stock', 'per_user_limit', 'is_active', 'sort_order',
    ];

    protected $attributes = ['is_active' => true, 'setup_fee' => 0, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'app_egg_ids' => 'array',
            'hypervisor_group_ids' => 'array',
            'setup_fee' => 'integer',
            'stock' => 'integer',
            'per_user_limit' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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
        $map = $this->prices->pluck('amount', 'cycle')->map(fn ($a) => (int) $a)->all();

        return array_filter(array_replace(array_fill_keys(Cycle::ALL, null), $map), fn ($a) => $a !== null);
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
        return $this->type === self::TYPE_VPS
            ? $this->package !== null && $this->package->is_active
            : $this->plan !== null && $this->plan->is_active;
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
        return $this->type === self::TYPE_VPS ? __('Serwer VPS') : __('Aplikacja');
    }
}
