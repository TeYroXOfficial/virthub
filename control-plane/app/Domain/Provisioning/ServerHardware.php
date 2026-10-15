<?php

namespace App\Domain\Provisioning;

use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;

/**
 * Sprzęt wirtualny maszyny KVM: magistrala i tryb I/O dysków, firmware
 * (BIOS/UEFI), vTPM, model procesora i rdzenie hosta (cpuset).
 *
 * Każde ustawienie dziedziczy: maszyna → szablon (firmware, vTPM — zależą od
 * obrazu systemu) albo węzeł (magistrala, I/O, model CPU, cpuset — zależą od
 * sprzętu i polityki węzła) → wartość domyślna. Zmiana istniejącej maszyny
 * trafia do definicji domeny i działa po wyłączeniu i włączeniu.
 */
class ServerHardware
{
    public const DISK_BUSES = ['virtio', 'scsi'];

    public const IO_MODES = ['native', 'threads', 'io_uring'];

    public const FIRMWARES = ['bios', 'uefi'];

    /** Modele CPU QEMU do wyboru (poza host-passthrough i host-model). */
    public const CPU_MODELS = [
        'host-passthrough', 'host-model',
        'EPYC', 'EPYC-Rome', 'EPYC-Milan', 'EPYC-Genoa',
        'Haswell-noTSX', 'Broadwell-noTSX', 'Skylake-Server', 'Cascadelake-Server', 'Icelake-Server', 'SapphireRapids', 'GraniteRapids',
    ];

    public const CPUSET_PATTERN = '/^[0-9]+(-[0-9]+)?(,\^?[0-9]+(-[0-9]+)?){0,99}$/';

    public function __construct(private readonly ServerProvisioner $provisioner) {}

    /**
     * Ustawienia po rozwiązaniu dziedziczenia, z informacją skąd pochodzą.
     *
     * @return array<string, array{value: mixed, source: string}>
     */
    public static function resolve(Server $server): array
    {
        $template = $server->template;
        $node = $server->hypervisor;
        $pick = function (array $chain, mixed $default): array {
            foreach ($chain as $source => $value) {
                if ($value !== null && $value !== '') {
                    return ['value' => $value, 'source' => $source];
                }
            }

            return ['value' => $default, 'source' => 'default'];
        };

        return [
            'disk_bus' => $pick(['server' => $server->hw_disk_bus, 'hypervisor' => $node?->hw_disk_bus], 'virtio'),
            'io_mode' => $pick(['server' => $server->hw_io_mode, 'hypervisor' => $node?->hw_io_mode], 'native'),
            'firmware' => $pick(['server' => $server->hw_firmware, 'template' => $template?->hw_firmware], 'bios'),
            'tpm' => $pick(['server' => $server->hw_tpm, 'template' => $template?->hw_tpm ? true : null], false),
            'cpu_model' => $pick(['server' => $server->hw_cpu_model, 'hypervisor' => $node?->hw_cpu_model], 'host-passthrough'),
            'cpuset' => $pick(['hypervisor' => $node?->hw_cpuset], null),
        ];
    }

    /** @return array{hardware?: array<string, mixed>} */
    public static function payload(Server $server): array
    {
        if ($server->isContainer()) {
            return [];
        }
        $hw = array_map(fn (array $s) => $s['value'], self::resolve($server));
        // vTPM bez UEFI nie wystartuje — wymuszamy UEFI zamiast wysyłać sprzeczne ustawienia.
        if ($hw['tpm']) {
            $hw['firmware'] = 'uefi';
        }
        $hw['tpm'] = (bool) $hw['tpm'];
        $hw['cpu_model'] = $hw['cpu_model'] === 'host-passthrough' ? null : $hw['cpu_model'];

        return ['hardware' => $hw];
    }

    /**
     * Zmiana ustawień maszyny. Klient może zmienić tylko rozruch (BIOS/UEFI);
     * pozostałe pola są dla personelu. null = dziedzicz.
     *
     * @param  array<string, mixed>  $settings
     */
    public function update(Server $server, array $settings, ?User $actor = null, bool $staff = true): ServerJob
    {
        if ($server->isContainer()) {
            throw new \DomainException(__('Ustawienia sprzętu dotyczą maszyn wirtualnych KVM.'));
        }
        if ($server->agent_uuid === null || $server->state->isTransitioning()) {
            throw new \DomainException(__('Maszyna nie jest gotowa — spróbuj, gdy zakończy bieżącą operację.'));
        }
        $allowed = $staff ? ['hw_disk_bus', 'hw_io_mode', 'hw_firmware', 'hw_tpm', 'hw_cpu_model'] : ['hw_firmware'];
        $changes = array_intersect_key($settings, array_flip($allowed));
        $server->forceFill($changes);
        $effective = self::resolve($server);
        if ($effective['tpm']['value'] && $effective['firmware']['value'] !== 'uefi') {
            throw new \DomainException(__('vTPM wymaga rozruchu UEFI — wybierz UEFI albo wyłącz vTPM.'));
        }
        $server->save();
        AuditLog::record('server.hardware', $server, $changes, $actor);

        return $this->provisioner->syncHardware($server, $actor);
    }
}
