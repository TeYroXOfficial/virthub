<?php

namespace App\Http\Resources;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Server */
class ServerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hostname' => $this->hostname,
            'label' => $this->label,
            'virtualization' => $this->virtualization?->value,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'state_tone' => $this->state->tone(),
            'state_message' => $this->state_message,
            'build_progress' => $this->build_progress,
            'suspended' => $this->isSuspended(),
            'suspension_reason' => $this->suspension_reason,

            'resources' => [
                'vcpu' => $this->vcpu,
                // Twardy limit CPU w procentach jednego rdzenia (null = bez limitu).
                'cpu_limit_percent' => $this->cpu_limit_percent,
                'ram_mb' => $this->ram_mb,
                'disk_gb' => $this->disk_gb,
                'bandwidth_gb' => $this->bandwidth_gb,
            ],

            'package' => $this->whenLoaded('package', fn () => [
                'slug' => $this->package->slug,
                'name' => $this->package->name,
            ]),

            'os' => $this->whenLoaded('template', fn () => [
                'id' => $this->template->id,
                'name' => $this->template->name,
                'family' => $this->template->family,
            ]),

            // System odczytany z wnętrza maszyny (null, dopóki nie odczytany).
            'guest_os' => $this->guest_os_name === null ? null : [
                'id' => $this->guest_os_id,
                'name' => $this->guest_os_name,
                'version' => $this->guest_os_version,
                'checked_at' => $this->guest_os_checked_at,
            ],
            'cpu_model' => $this->hypervisor?->cpuModel(),
            // Transfer w bieżącym miesiącu, w bajtach (1 GB = 1000³ B).
            'traffic' => app(\App\Domain\Metrics\Traffic::class)->usage($this->resource),

            'ip_addresses' => $this->whenLoaded('ipAddresses', fn () => $this->ipAddresses
                ->map(fn ($ip) => [
                    'address' => $ip->address,
                    'version' => $ip->version,
                    'primary' => $ip->is_primary,
                    'rdns' => $ip->rdns,
                    'type' => $ip->pool?->type ?? 'public',
                    // Za NAT-em maszyna jest osiągalna tylko przez blok portów;
                    // port_forwards to pełne mapowanie (usługi stałe, ustawione, 1:1).
                    'nat_ports' => $ip->natPorts(),
                    'port_forwards' => $ip->natPorts() === null ? [] : array_map(
                        fn (array $e) => \Illuminate\Support\Arr::except($e, ['id']),
                        \App\Domain\Network\PortForwarding::mapping($ip, $this->resource),
                    ),
                ])->values()),

            // Widoczne tylko dla personelu — klient nie musi wiedzieć, na którym
            // węźle stoi jego maszyna, a nam ta informacja ułatwia wsparcie.
            'hypervisor' => $this->when(
                $request->user()?->isStaff() && $this->relationLoaded('hypervisor'),
                fn () => [
                    'id' => $this->hypervisor?->id,
                    'name' => $this->hypervisor?->name,
                ],
            ),

            'created_at' => $this->created_at,
            'last_synced_at' => $this->last_synced_at,
        ];
    }
}
