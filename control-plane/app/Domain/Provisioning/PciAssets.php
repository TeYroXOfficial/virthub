<?php

namespace App\Domain\Provisioning;

use App\Domain\Agent\AgentClient;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\PciAsset;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Urządzenia PCI (GPU, karty) przekazywane maszynom KVM. Każde należy do
 * jednego hypervisora i najwyżej jednej maszyny. Zmiana trafia na węzeł
 * zleceniem „hostdevs”; działa od następnego uruchomienia maszyny (albo od
 * razu z restartem).
 */
class PciAssets
{
    public function __construct(private readonly ServerProvisioner $provisioner) {}

    /** @return array{iommu: bool, devices: list<array<string, mixed>>} urządzenia węzła z informacją o rejestracji */
    public function scan(Hypervisor $node): array
    {
        $report = (new AgentClient($node))->pciDevices();
        $known = $node->pciAssets()->pluck('id', 'pci_address');
        $devices = array_map(fn (array $d) => $d + ['asset_id' => $known[$d['address'] ?? ''] ?? null], (array) ($report['devices'] ?? []));

        return ['iommu' => (bool) ($report['iommu'] ?? false), 'devices' => $devices];
    }

    /** @param  list<int>  $assetIds */
    public function assign(Server $server, array $assetIds, bool $restart = false, ?User $actor = null): void
    {
        if ($server->isContainer()) {
            throw ValidationException::withMessages(['pci' => __('Urządzenia PCI można przekazać tylko maszynom KVM.')]);
        }
        DB::transaction(function () use ($server, $assetIds) {
            $assets = PciAsset::query()->whereIn('id', $assetIds)->lockForUpdate()->get();
            foreach ($assets as $asset) {
                if ($asset->hypervisor_id !== $server->hypervisor_id) {
                    throw ValidationException::withMessages(['pci' => __('Urządzenie :name jest na innym węźle niż maszyna.', ['name' => $asset->name])]);
                }
                if ($asset->server_id !== null && $asset->server_id !== $server->id) {
                    throw ValidationException::withMessages(['pci' => __('Urządzenie :name jest już przypisane do innej maszyny.', ['name' => $asset->name])]);
                }
            }
            PciAsset::query()->where('server_id', $server->id)->whereNotIn('id', $assets->pluck('id'))->update(['server_id' => null]);
            PciAsset::query()->whereIn('id', $assets->pluck('id'))->update(['server_id' => $server->id]);
        });
        AuditLog::record('server.pci', $server, ['assets' => array_values($assetIds), 'restart' => $restart], $actor);
        if ($server->agent_uuid) {
            $this->provisioner->syncHostdevs($server, $restart, $actor);
        }
    }

    /** Pakiet z GPU: wolne urządzenia danego rodzaju z węzła nowej maszyny. Zwraca liczbę przydzielonych. */
    public function assignFromPackage(Server $server): int
    {
        $package = $server->package;
        if ($package === null || ! $package->pci_type || $package->pci_count < 1 || $server->isContainer()) {
            return 0;
        }
        $ids = PciAsset::query()->where('hypervisor_id', $server->hypervisor_id)->where('type', $package->pci_type)
            ->whereNull('server_id')->orderBy('pci_address')->limit($package->pci_count)->pluck('id')->all();
        if (count($ids) < $package->pci_count) {
            $server->forceFill(['state_message' => __('Na węźle zabrakło wolnych urządzeń :type z pakietu (:have z :need) — przydziel je ręcznie.', [
                'type' => $package->pci_type, 'have' => count($ids), 'need' => $package->pci_count,
            ])])->save();
        }
        if ($ids !== []) {
            $this->assign($server, $ids, restart: true);
        }

        return count($ids);
    }

    public function release(Server $server): void
    {
        PciAsset::query()->where('server_id', $server->id)->update(['server_id' => null]);
    }
}
