<?php

namespace Database\Factories;

use App\Models\Hypervisor;
use App\Models\HypervisorGroup;
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

    /** Pula wspólna dla grupy węzłów zamiast jednego węzła. */
    public function forGroup(HypervisorGroup $group): static
    {
        return $this->state(fn () => ['hypervisor_id' => null, 'hypervisor_group_id' => $group->id]);
    }

    /** Sieć prywatna za NAT-em węzła, z blokiem 20 portów na maszynę od 10000. */
    public function nat(): static
    {
        return $this->state(fn () => [
            'name' => 'Pula NAT',
            'type' => IpPool::TYPE_NAT,
            'cidr' => '10.10.0.0/24',
            'gateway' => '10.10.0.1',
            'prefix' => 24,
            'nat_port_start' => 10000,
            'nat_ports_per_server' => 20,
        ]);
    }

    /** 2001:db8::/32 — prefiks dokumentacyjny z RFC 3849. */
    public function ipv6(): static
    {
        return $this->state(fn () => [
            'name' => 'Pula IPv6',
            'cidr' => '2001:db8:10::/64',
            'version' => 6,
            'gateway' => '2001:db8:10::1',
            'prefix' => 64,
            'nameservers' => null,
        ]);
    }
}
