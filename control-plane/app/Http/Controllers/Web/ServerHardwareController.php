<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\ServerDisks;
use App\Domain\Provisioning\ServerHardware;
use App\Domain\Windows\DirectRdp;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerDisk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Dyski i sprzęt maszyny na jej stronie: personel zmienia wszystko, klient tylko rozruch BIOS/UEFI. */
class ServerHardwareController extends Controller
{
    public function addDisk(Request $request, Server $server, ServerDisks $disks): RedirectResponse
    {
        $this->authorize('manageResources', $server);
        $data = $request->validate(['size_gb' => ['required', 'integer', 'min:1', 'max:65536']]);

        return $this->guard($server, fn () => $disks->add($server, (int) $data['size_gb'], $request->user()), __('Dysk zostanie dodany do maszyny.'));
    }

    public function growDisk(Request $request, Server $server, ServerDisks $disks, ?ServerDisk $disk = null): RedirectResponse
    {
        $this->authorize('manageResources', $server);
        abort_if($disk !== null && $disk->server_id !== $server->id, 404);
        $data = $request->validate(['size_gb' => ['required', 'integer', 'min:1', 'max:65536']]);

        return $this->guard($server, fn () => $disks->grow($server, $disk, (int) $data['size_gb'], $request->user()),
            __('Dysk zostanie powiększony. W systemie rozszerz partycję i system plików (np. growpart i resize2fs).'));
    }

    public function removeDisk(Request $request, Server $server, ServerDisk $disk, ServerDisks $disks): RedirectResponse
    {
        $this->authorize('manageResources', $server);
        abort_unless($disk->server_id === $server->id, 404);
        $request->validate(['confirm' => ['accepted']]);

        return $this->guard($server, fn () => $disks->remove($disk, $request->user()), __('Dysk zostanie odłączony i usunięty razem z danymi.'));
    }

    public function update(Request $request, Server $server, ServerHardware $hardware): RedirectResponse
    {
        $staff = $request->user()->can('manageResources', $server);
        $staff || $this->authorize('rebuild', $server);
        $nullable = fn (array $values) => ['nullable', Rule::in($values)];
        $data = $request->validate([
            'hw_firmware' => $nullable(ServerHardware::FIRMWARES),
            'hw_disk_bus' => $nullable(ServerHardware::DISK_BUSES),
            'hw_io_mode' => $nullable(ServerHardware::IO_MODES),
            'hw_cpu_model' => $nullable(ServerHardware::CPU_MODELS),
            'hw_tpm' => ['nullable', Rule::in(['', '0', '1'])],
            'hw_hyperv' => $nullable(ServerHardware::HYPERV_PROFILES),
        ]);
        $settings = array_map(fn ($v) => $v === '' ? null : $v, $data) + array_fill_keys(['hw_firmware', 'hw_disk_bus', 'hw_io_mode', 'hw_cpu_model', 'hw_tpm', 'hw_hyperv'], null);
        $settings['hw_tpm'] = $settings['hw_tpm'] === null ? null : $settings['hw_tpm'] === '1';

        return $this->guard($server, fn () => $hardware->update($server, $settings, $request->user(), $staff),
            __('Zapisano ustawienia sprzętu. Zaczną działać po wyłączeniu i włączeniu maszyny (restart z systemu nie wystarczy).'));
    }

    /** Plik .rdp (Windows) — dane logowania wpisuje klient, hasło nie trafia do pliku. */
    public function rdp(Server $server): Response
    {
        $this->authorize('view', $server);
        abort_unless($server->osFamily() === 'windows' && DirectRdp::address($server) !== null, 404);

        return response(DirectRdp::file($server), 200, [
            'Content-Type' => 'application/x-rdp',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '-', $server->hostname).'.rdp"',
        ]);
    }

    private function guard(Server $server, \Closure $action, string $message): RedirectResponse
    {
        $to = redirect()->to(route('panel.servers.show', $server).'#hardware');
        try {
            $action();

            return $to->with('status', $message);
        } catch (\DomainException $e) {
            return $to->withErrors(['hardware' => $e->getMessage()]);
        }
    }
}
