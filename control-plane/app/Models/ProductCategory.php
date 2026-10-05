<?php

namespace App\Models;

use App\Domain\Billing\Cycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_active', 'sort_order', 'allowed_cycles'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer', 'allowed_cycles' => 'array'];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Czy produkty tej kategorii mogą być sprzedawane w danym okresie — po jednostce (godziny, dni…); puste = wszystkie. */
    public function allowsCycle(string $cycle): bool
    {
        if (empty($this->allowed_cycles)) {
            return true;
        }
        try {
            return in_array(Cycle::unit($cycle), $this->allowed_cycles, true) || in_array($cycle, $this->allowed_cycles, true);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @param  Builder<ProductCategory>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
