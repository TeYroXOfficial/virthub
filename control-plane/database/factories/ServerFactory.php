<?php

namespace Database\Factories;

use App\Enums\ServerState;
use App\Models\Hypervisor;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Server> */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'hypervisor_id' => Hypervisor::factory(),
            'vps_package_id' => VpsPackage::factory(),
            'os_template_id' => OsTemplate::factory(),
            'hostname' => fake()->unique()->domainWord().'.example.com',
            'state' => ServerState::Running,
            'virtualization' => 'kvm',
            'vcpu' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 50,
            'bandwidth_gb' => 2000,
            'agent_uuid' => (string) Str::uuid(),
            'mac_address' => '52:54:00:12:34:56',
            'vnc_port' => 5901,
            'build_progress' => 100,
        ];
    }

    public function stopped(): static
    {
        return $this->state(fn () => ['state' => ServerState::Stopped]);
    }

    public function building(): static
    {
        return $this->state(fn () => [
            'state' => ServerState::Building,
            'agent_uuid' => null,
            'build_progress' => 0,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'state' => ServerState::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Nieopłacona faktura.',
        ]);
    }
}
