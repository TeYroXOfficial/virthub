<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Provisioning\PciAssets;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\PciAsset;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Hypervisor → Urządzenia PCI: rejestracja GPU/kart i przypisanie do maszyn. */
class PciAssetController extends Controller
{
    public function __construct(private readonly PciAssets $assets) {}

    public function index(Hypervisor $hypervisor): View
    {
        $scan = null;
        $error = null;
        try {
            $scan = $this->assets->scan($hypervisor);
        } catch (AgentException $e) {
            $error = $e->getMessage();
        }

        return view('panel.admin.pci', [
            'node' => $hypervisor,
            'assets' => $hypervisor->pciAssets()->with('server:id,hostname')->get(),
            'scan' => $scan,
            'scanError' => $error,
            'servers' => $hypervisor->servers()->where('virtualization', 'kvm')->orderBy('hostname')->get(['id', 'hostname']),
        ]);
    }

    public function store(Request $request, Hypervisor $hypervisor): RedirectResponse
    {
        $data = $request->validate([
            'pci_address' => ['required', 'regex:/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/', Rule::unique('pci_assets')->where('hypervisor_id', $hypervisor->id)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(PciAsset::TYPES)],
            'vendor_id' => ['nullable', 'regex:/^[0-9a-f]{4}$/'],
            'device_id' => ['nullable', 'regex:/^[0-9a-f]{4}$/'],
            'iommu_group' => ['nullable', 'integer', 'min:0'],
        ]);
        $asset = $hypervisor->pciAssets()->create($data);
        AuditLog::record('pci_asset.created', $asset, ['address' => $asset->pci_address, 'node' => $hypervisor->name]);

        return back()->with('status', __('Dodano urządzenie :name.', ['name' => $asset->name]));
    }

    public function update(Request $request, PciAsset $asset): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(PciAsset::TYPES)],
            'custom_xml' => ['nullable', 'string', 'max:4000'],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')->where('hypervisor_id', $asset->hypervisor_id)],
        ]);
        if (! empty($data['custom_xml'])) {
            $xml = @simplexml_load_string($data['custom_xml']);
            if ($xml === false || $xml->getName() !== 'hostdev') {
                return back()->withErrors(['custom_xml' => __('Własny XML musi być pojedynczym elementem <hostdev>.')]);
            }
        }
        $asset->update(['name' => $data['name'], 'type' => $data['type'], 'custom_xml' => ($data['custom_xml'] ?? null) ?: null]);
        AuditLog::record('pci_asset.updated', $asset, ['name' => $asset->name]);

        // Zmiana właściciela: zdejmij z poprzedniej maszyny, dodaj do nowej (z restartem).
        $target = isset($data['server_id']) ? (int) $data['server_id'] : null;
        if ($target !== $asset->server_id) {
            if ($asset->server_id !== null) {
                $previous = Server::find($asset->server_id);
                if ($previous) {
                    $this->assets->assign($previous, $previous->pciAssets()->whereKeyNot($asset->id)->pluck('id')->all(), $request->boolean('restart'), $request->user());
                }
            }
            if ($target !== null) {
                $server = Server::findOrFail($target);
                $this->assets->assign($server, [...$server->pciAssets()->pluck('id')->all(), $asset->id], $request->boolean('restart'), $request->user());
            }
        }

        return back()->with('status', __('Zapisano urządzenie :name.', ['name' => $asset->name]));
    }

    public function destroy(PciAsset $asset): RedirectResponse
    {
        if ($asset->server_id !== null) {
            return back()->withErrors(['pci' => __('Urządzenie jest przypisane do maszyny — najpierw je odepnij.')]);
        }
        AuditLog::record('pci_asset.deleted', $asset, ['address' => $asset->pci_address]);
        $asset->delete();

        return back()->with('status', __('Urządzenie usunięte z panelu.'));
    }
}
