<?php

namespace App\Domain\Agent;

use App\Models\IpAddress;
use App\Models\Server;

/**
 * Tłumaczenie modeli panelu na ładunek, którego oczekuje agent.
 *
 * Jedno miejsce, w którym wiedza o kontrakcie agenta styka się z bazą — gdy
 * zmieni się schemat po stronie agenta, poprawka jest tutaj, a nie w pięciu
 * zadaniach kolejki.
 */
class ServerPayload
{
    /** @return list<array<string, mixed>> */
    public static function interfaces(Server $server): array
    {
        return $server->ipAddresses()
            ->with('pool')
            ->get()
            ->map(fn (IpAddress $ip) => [
                'address' => $ip->address,
                'prefix' => $ip->pool->prefix,
                'gateway' => $ip->pool->gateway,
                'version' => $ip->version,
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    public static function nameservers(Server $server): array
    {
        $primary = $server->ipAddresses()->with('pool')->first();

        return $primary?->pool->nameserverList() ?? ['1.1.1.1', '9.9.9.9'];
    }

    /** @param  list<string>  $sshKeys */
    public static function forCreate(Server $server, array $sshKeys = []): array
    {
        return [
            'server_id' => $server->id,
            'hostname' => $server->hostname,
            'vcpu' => $server->vcpu,
            'ram_mb' => $server->ram_mb,
            'disk_gb' => $server->disk_gb,
            'template' => $server->template->image_file,
            'interfaces' => self::interfaces($server),
            'ssh_keys' => array_values($sshKeys),
            'root_password' => $server->root_password,
            'nameservers' => self::nameservers($server),
        ];
    }

    public static function forNetwork(Server $server): array
    {
        return [
            'interfaces' => self::interfaces($server),
            'firewall' => $server->firewallRules
                ->map(fn ($rule) => $rule->toAgentPayload())
                ->all(),
        ];
    }
}
