<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Urządzenie PCI hypervisora (GPU, karta sieciowa…) do przekazania jednej maszynie. */
class PciAsset extends Model
{
    public const TYPES = ['gpu', 'nic', 'nvme', 'other'];

    protected $fillable = ['hypervisor_id', 'name', 'type', 'pci_address', 'vendor_id', 'device_id', 'iommu_group', 'custom_xml', 'server_id'];

    protected function casts(): array
    {
        return ['iommu_group' => 'integer'];
    }

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return array{address:string, xml?:string} */
    public function toAgentPayload(): array
    {
        return array_filter(['address' => $this->pci_address, 'xml' => $this->custom_xml ?: null]);
    }
}
