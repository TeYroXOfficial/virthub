<?php

namespace Database\Factories;

use App\Models\FirewallRule;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FirewallRule> */
class FirewallRuleFactory extends Factory
{
    protected $model = FirewallRule::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'managed_by' => FirewallRule::MANAGED_BY_CUSTOMER,
            'enabled' => true,
            'action' => 'accept',
            'direction' => 'in',
            'protocol' => 'tcp',
            'port_from' => fake()->numberBetween(1024, 65000),
            'port_to' => null,
            'source' => null,
            'position' => fake()->numberBetween(1, 1000),
        ];
    }
}
