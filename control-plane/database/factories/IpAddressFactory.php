<?php

namespace Database\Factories;

use App\Models\IpAddress;
use App\Models\IpPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IpAddress> */
class IpAddressFactory extends Factory
{
    protected $model = IpAddress::class;

    public function definition(): array
    {
        $pool = IpPool::factory();

        return [
            'ip_pool_id' => $pool,
            'hypervisor_id' => fn (array $attributes) => IpPool::find($attributes['ip_pool_id'])->hypervisor_id,
            'address' => '203.0.113.'.fake()->unique()->numberBetween(10, 250),
            'version' => 4,
            'is_primary' => false,
            'is_reserved' => false,
        ];
    }
}
