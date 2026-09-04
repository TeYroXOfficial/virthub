<?php

namespace Database\Factories;

use App\Models\Hypervisor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Hypervisor> */
class HypervisorFactory extends Factory
{
    protected $model = Hypervisor::class;

    public function definition(): array
    {
        $name = 'node'.fake()->unique()->numberBetween(1, 999);

        return [
            'name' => $name,
            'hostname' => "{$name}.virthub.internal",
            'agent_url' => "https://{$name}.virthub.internal:8899",
            'agent_token' => Str::random(64),
            'callback_secret' => Str::random(64),
            'status' => Hypervisor::STATUS_ONLINE,
            'cpu_cores_total' => 64,
            'ram_mb_total' => 262144,
            'disk_gb_total' => 4000,
            'cpu_cores_used' => 0,
            'ram_mb_used' => 0,
            'disk_gb_used' => 0,
            'last_seen_at' => now(),
            'accepts_new_servers' => true,
            'bridge' => 'br0',
        ];
    }

    /** Węzeł, który nie odpowiada — nie powinien dostać nowych maszyn. */
    public function offline(): static
    {
        return $this->state(fn () => [
            'status' => Hypervisor::STATUS_OFFLINE,
            'last_seen_at' => now()->subHour(),
        ]);
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes) => [
            'cpu_cores_used' => $attributes['cpu_cores_total'],
            'ram_mb_used' => $attributes['ram_mb_total'],
            'disk_gb_used' => $attributes['disk_gb_total'],
        ]);
    }
}
