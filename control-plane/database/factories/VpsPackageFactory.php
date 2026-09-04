<?php

namespace Database\Factories;

use App\Models\VpsPackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VpsPackage> */
class VpsPackageFactory extends Factory
{
    protected $model = VpsPackage::class;

    public function definition(): array
    {
        $name = 'VPS '.fake()->unique()->numerify('S-###');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'vcpu' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 50,
            'bandwidth_gb' => 2000,
            'ip_count' => 1,
            'price_hint_cents' => 4900,
            'currency' => 'PLN',
            'is_active' => true,
        ];
    }

    public function small(): static
    {
        return $this->state(fn () => ['vcpu' => 1, 'ram_mb' => 2048, 'disk_gb' => 20]);
    }

    public function large(): static
    {
        return $this->state(fn () => ['vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 200]);
    }
}
