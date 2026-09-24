<?php

namespace Database\Factories;

use App\Models\HypervisorGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HypervisorGroup> */
class HypervisorGroupFactory extends Factory
{
    protected $model = HypervisorGroup::class;

    public function definition(): array
    {
        return [
            'name' => 'DC'.fake()->unique()->numberBetween(1, 999),
            'description' => null,
        ];
    }
}
