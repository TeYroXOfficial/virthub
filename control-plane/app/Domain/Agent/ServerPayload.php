<?php

namespace App\Domain\Agent;

use App\Domain\Metrics\Traffic;
use App\Domain\Network\Firewall;
use App\Domain\Network\PortForwarding;
use App\Domain\Network\PrivateNetworks;
use App\Domain\Provisioning\ServerDisks;
use App\Domain\Provisioning\ServerHardware;
use App\Models\IpAddress;
use App\Models\OsTemplate;
use App\Models\Provisioner;
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
            ->map(fn (IpAddress $ip) => self::interface($ip, $server))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public static function interface(IpAddress $ip, ?Server $server = null): array
    {
        $pool = $ip->pool;
        $interface = [
            'address' => $ip->address,
            'prefix' => $pool->prefix,
            'gateway' => $pool->gateway,
            'version' => $ip->version,
            'mode' => $pool->isNat() ? 'nat' : ($pool->isRouted() ? 'routed' : 'bridged'),
        ];
        if ($pool->isRouted()) {
            // Adres pojedynczy, brama on-link na mostku routowanym węzła.
            $interface['prefix'] = $ip->version === 6 ? 128 : 32;
            $interface['gateway'] = $ip->version === 6 ? 'fe80::1' : '169.254.0.1';
        }
        if ($pool->vlan_id && ! $pool->isNat()) {
            $interface['vlan'] = (int) $pool->vlan_id;
        }

        if ($pool->isNat()) {
            $ports = $pool->natPortsFor($ip->address);
            $interface['nat'] = [
                'network' => $pool->cidr,
                'snat_address' => $pool->nat_public_address,
                'port_from' => $ports['from'] ?? null,
                'port_to' => $ports['to'] ?? null,
            ];
            if ($ports !== null) {
                // Pełne mapowanie bloku: usługi dostępu wg systemu, porty
                // ustawione przez klienta; reszta bloku zamknięta.
                $interface['nat']['forwards'] = PortForwarding::agentPayload($ip, $server);
            }
        }

        return $interface;
    }

    /** @return list<string> */
    public static function nameservers(Server $server): array
    {
        $primary = $server->ipAddresses()->with('pool')->orderByDesc('is_primary')->orderBy('id')->first();

        return $primary?->pool->nameserverList() ?? ['1.1.1.1', '9.9.9.9'];
    }

    /**
     * MAC karty maszyny: przypisany przez dostawcę do adresu IP (najpierw
     * głównego). Brak — agent nada stały MAC z puli 52:54:00.
     */
    public static function mac(Server $server): ?string
    {
        return $server->ipAddresses()->whereNotNull('mac_address')
            ->orderByDesc('is_primary')->orderBy('version')->orderBy('id')
            ->value('mac_address');
    }

    /** @param  list<string>  $sshKeys */
    public static function forCreate(Server $server, array $sshKeys = []): array
    {
        return [
            'server_id' => $server->id,
            'hostname' => $server->hostname,
            'vcpu' => $server->vcpu,
            'cpu_limit_percent' => $server->cpu_limit_percent,
            'ram_mb' => $server->ram_mb,
            'disk_gb' => $server->disk_gb,
            'template' => $server->template->image_file,
            'interfaces' => self::interfaces($server),
            'ssh_keys' => array_values($sshKeys),
            'root_password' => $server->root_password,
            'nameservers' => self::nameservers($server),
            'os_type' => self::osType($server->template),
            'mac' => self::mac($server),
        ] + self::scripts($server) + self::filter($server) + self::storage($server) + self::privateNics($server)
            + ServerHardware::payload($server) + self::extraDisks($server) + self::swap($server)
            + array_filter(['bandwidth_mbps' => Traffic::bandwidthMbps($server)]);
    }

    /** Plik wymiany z kreatora zamówienia (Linux; cloud-init). */
    public static function swap(Server $server): array
    {
        return $server->swap_mb && ! $server->isContainer() && self::osType($server->template) === 'linux' ? ['swap_mb' => (int) $server->swap_mb] : [];
    }

    /** @return array{extra_disks?: list<array{slot:int, size_gb:int}>} */
    public static function extraDisks(Server $server): array
    {
        $disks = ServerDisks::payload($server);

        return $disks === [] ? [] : ['extra_disks' => $disks];
    }

    /**
     * Karty w sieciach prywatnych. Brak klucza dla maszyn bez kart — starszy agent go nie zna.
     *
     * @return array{private_nics?: list<array<string, mixed>>}
     */
    public static function privateNics(Server $server): array
    {
        $nics = PrivateNetworks::payload($server);

        return $nics === [] ? [] : ['private_nics' => $nics];
    }

    /**
     * Magazyn dysku. Brak klucza = katalog domyślny węzła (jak w starszych wersjach).
     *
     * @return array{storage?: array<string, mixed>}
     */
    public static function storage(Server $server): array
    {
        $datastore = $server->datastore;

        return $datastore && ! $server->isContainer() ? ['storage' => $datastore->toAgentPayload()] : [];
    }

    /**
     * Provisionery szablonu ze zmiennymi serwera. Pusty klucz nie jest wysyłany —
     * starszy agent i tak by go zignorował, a ładunek bez skryptów się nie zmienia.
     *
     * @return array{scripts?: list<array{name:string, content:string, path:string}>}
     */
    public static function scripts(Server $server): array
    {
        $template = $server->template;
        if ($template === null || self::osType($template) === 'windows') {
            return [];
        }
        $vars = self::scriptVars($server);
        $scripts = $template->provisioners()->where('is_active', true)->get()
            ->map(fn (Provisioner $p) => [
                'name' => $p->name,
                'content' => strtr($p->content, $vars),
                'path' => $p->path,
            ])->values()->all();

        return $scripts === [] ? [] : ['scripts' => $scripts];
    }

    /** @return array<string, string> {{ zmienna }} → wartość */
    public static function scriptVars(Server $server): array
    {
        $ips = $server->ipAddresses()->orderByDesc('is_primary')->orderBy('id')->get();
        $values = [
            'server.id' => (string) $server->id,
            'server.hostname' => (string) $server->hostname,
            'server.label' => (string) $server->label,
            'server.vcpu' => (string) $server->vcpu,
            'server.ram_mb' => (string) $server->ram_mb,
            'server.disk_gb' => (string) $server->disk_gb,
            'server.ipv4' => (string) $ips->firstWhere('version', 4)?->address,
            'server.ipv6' => (string) $ips->firstWhere('version', 6)?->address,
            'server.template' => (string) $server->template?->name,
            'user.id' => (string) $server->user_id,
            'user.email' => (string) $server->user?->email,
        ];
        $out = [];
        foreach ($values as $key => $value) {
            $out['{{'.$key.'}}'] = $value;
            $out['{{ '.$key.' }}'] = $value;
        }

        return $out;
    }

    /** Windows dostaje nośnik config-2 (cloudbase-init) i ustawienia maszyny pod Windows. */
    public static function osType(?OsTemplate $template): string
    {
        return $template?->family === 'windows' ? 'windows' : 'linux';
    }

    public static function forNetwork(Server $server): array
    {
        return [
            'interfaces' => self::interfaces($server),
            // Kolejność ma znaczenie: reguły administratora przed regułami
            // klienta (relacja firewallRules już je tak sortuje).
            'firewall' => $server->firewallRules()->get()
                ->filter(fn ($rule) => $rule->enabled)
                ->map(fn ($rule) => $rule->toAgentPayload())
                ->values()
                ->all(),
            'policy' => Firewall::policyPayload($server),
            // Zawsze wysyłane: null zdejmuje limit (koniec dławienia, brak prędkości portu).
            'bandwidth_mbps' => Traffic::bandwidthMbps($server),
            // Karty prywatne: zapora na każdej z nich (reguły z interface = nX).
            'private_nics' => PrivateNetworks::payload($server),
        ] + self::filter($server);
    }

    /** @return array{filter?: array<string, mixed>} */
    public static function filter(Server $server): array
    {
        $profile = $server->effectiveNetworkFilter();

        return $profile === null ? [] : ['filter' => $profile->toAgentPayload()];
    }
}
