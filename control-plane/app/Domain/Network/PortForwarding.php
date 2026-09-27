<?php

namespace App\Domain\Network;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\NatPortForward;
use App\Models\Server;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Przekierowania portów adresu za NAT-em.
 *
 * Każdy adres NAT ma stały blok portów węzła (patrz IpPool::natPortsFor).
 * Pierwsze porty bloku są zawsze zajęte przez usługi dostępu zależne od
 * systemu — SSH/SFTP w Linuksie, RDP (i SSH) w Windows — żeby klient zawsze
 * mógł się dostać do maszyny, niezależnie od tego, co przestawi. Pozostałe
 * porty klient kieruje na dowolne porty w maszynie; nieustawione przechodzą
 * 1:1 na ten sam numer.
 */
class PortForwarding
{
    public const KIND_FIXED = 'fixed';

    public const KIND_CUSTOM = 'custom';

    public const KIND_DIRECT = 'direct';

    /**
     * Usługi dostępu w kolejności, w jakiej dostają pierwsze porty bloku.
     *
     * @return list<array{label: string, internal: int}>
     */
    public static function fixedServices(?Server $server): array
    {
        return $server?->osFamily() === 'windows'
            ? [['label' => 'RDP', 'internal' => 3389], ['label' => 'SSH / SFTP', 'internal' => 22]]
            : [['label' => 'SSH / SFTP', 'internal' => 22]];
    }

    /**
     * Pełne mapowanie bloku portów adresu.
     *
     * @return list<array{external: int, internal: int, label: ?string, kind: string, id: ?int}>
     */
    public static function mapping(IpAddress $ip, ?Server $server = null): array
    {
        $server ??= $ip->server;
        $block = $ip->pool?->natPortsFor($ip->address);

        if ($block === null) {
            return [];
        }

        $entries = [];
        foreach (self::fixedServices($server) as $i => $service) {
            $port = $block['from'] + $i;
            if ($port <= $block['to']) {
                $entries[$port] = [
                    'external' => $port, 'internal' => $service['internal'],
                    'label' => $service['label'], 'kind' => self::KIND_FIXED, 'id' => null,
                ];
            }
        }

        $custom = $server === null ? collect() : NatPortForward::query()
            ->where('server_id', $server->id)
            ->where('ip_address_id', $ip->id)
            ->get();

        foreach ($custom as $forward) {
            $port = $forward->external_port;
            // Usługa stała wygrywa — np. po reinstalacji z Linuksa na Windows
            // drugi port bloku przechodzi na SSH.
            if ($port < $block['from'] || $port > $block['to'] || isset($entries[$port])) {
                continue;
            }
            $entries[$port] = [
                'external' => $port, 'internal' => $forward->internal_port,
                'label' => $forward->label, 'kind' => self::KIND_CUSTOM, 'id' => $forward->id,
            ];
        }

        for ($port = $block['from']; $port <= $block['to']; $port++) {
            $entries[$port] ??= [
                'external' => $port, 'internal' => $port,
                'label' => null, 'kind' => self::KIND_DIRECT, 'id' => null,
            ];
        }

        ksort($entries);

        return array_values($entries);
    }

    /**
     * Mapowanie dla agenta: port węzła → port w maszynie.
     *
     * @return list<array{external: int, internal: int}>
     */
    public static function agentPayload(IpAddress $ip, ?Server $server = null): array
    {
        return array_map(
            fn (array $e) => ['external' => $e['external'], 'internal' => $e['internal']],
            self::mapping($ip, $server),
        );
    }

    /** Port zewnętrzny, pod którym widać usługę w maszynie (np. 22) — pierwszy pasujący. */
    public static function externalFor(IpAddress $ip, int $internal, ?Server $server = null): ?int
    {
        foreach (self::mapping($ip, $server) as $entry) {
            if ($entry['internal'] === $internal && $entry['kind'] !== self::KIND_DIRECT) {
                return $entry['external'];
            }
        }

        return null;
    }

    /** Ustawia (albo nadpisuje) przekierowanie portu z bloku adresu. */
    public function set(Server $server, IpAddress $ip, int $external, int $internal, ?string $label, ?User $actor = null): NatPortForward
    {
        $block = $ip->server_id === $server->id ? $ip->pool?->natPortsFor($ip->address) : null;

        if ($block === null) {
            throw ValidationException::withMessages([
                'external_port' => __('Ten adres nie ma przekierowanych portów.'),
            ]);
        }

        if ($external < $block['from'] || $external > $block['to']) {
            throw ValidationException::withMessages([
                'external_port' => __('Port zewnętrzny musi należeć do bloku :from–:to.', $block),
            ]);
        }

        $fixed = $block['from'] + count(self::fixedServices($server)) - 1;
        if ($external <= $fixed) {
            throw ValidationException::withMessages([
                'external_port' => __('Port :port jest zarezerwowany dla dostępu do maszyny (SSH/RDP).', ['port' => $external]),
            ]);
        }

        $forward = NatPortForward::query()->updateOrCreate(
            ['ip_address_id' => $ip->id, 'external_port' => $external],
            ['server_id' => $server->id, 'internal_port' => $internal, 'label' => $label ?: null],
        );

        AuditLog::record('server.port_forward', $server, [
            'address' => $ip->address, 'external' => $external, 'internal' => $internal,
        ], $actor);

        return $forward;
    }

    public function remove(NatPortForward $forward, ?User $actor = null): void
    {
        AuditLog::record('server.port_forward_removed', $forward->server, [
            'external' => $forward->external_port, 'internal' => $forward->internal_port,
        ], $actor);

        $forward->delete();
    }
}
