<?php

namespace Database\Factories;

use App\Models\Hypervisor;
use App\Models\IpPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IpPool> */
class IpPoolFactory extends Factory
{
    protected $model = IpPool::class;

    public function definition(): array
    {
        // 203.0.113.0/24 to pula TEST-NET-3 zarezerwowana przez RFC 5737 do
        // dokumentacji — bezpieczna w testach, nie należy do nikogo.
        return [
            'hypervisor_id' => Hypervisor::factory(),
            'name' => 'Pula testowa',
            'cidr' => '203.0.113.0/24',
            'version' => 4,
            'gateway' => '203.0.113.1',
            'prefix' => 24,
            'nameservers' => ['1.1.1.1', '9.9.9.9'],
        ];
    }
}
