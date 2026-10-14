<?php

namespace App\Domain\Network;

use App\Domain\Provisioning\ServerProvisioner;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\ServerNic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sieci prywatne klienta i dodatkowe karty maszyn.
 *
 * Sieć to izolowany mostek na jednym węźle: bez łącza w górę, bez adresu
 * węzła i bez routingu. Podłączyć można tylko maszyny KVM tego samego
 * właściciela na tym samym węźle — każda dostaje w niej osobną kartę (slot
 * 1–7, karta główna to slot 0) i adres z puli sieci, który cloud-init ustawi
 * przy tworzeniu albo reinstalacji.
 */
class PrivateNetworks
{
    /** Zakresy, z których klient może wybrać adresację sieci. */
    private const PRIVATE_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    public function __construct(private readonly ServerProvisioner $provisioner) {}

    public function create(User $owner, Hypervisor $node, string $name, ?string $cidr = null, ?User $actor = null): PrivateNetwork
    {
        $limit = (int) config('virthub.private_networks.per_user', 10);
        if (PrivateNetwork::query()->where('user_id', $owner->id)->count() >= $limit) {
            throw new \DomainException(__('Osiągnięto limit sieci prywatnych (:limit).', ['limit' => $limit]));
        }
        $cidr = $this->normaliseCidr($cidr ?: $this->suggestCidr($owner));
        $network = PrivateNetwork::create(['user_id' => $owner->id, 'hypervisor_id' => $node->id, 'name' => trim($name), 'cidr' => $cidr]);
        AuditLog::record('private_network.created', $network, ['name' => $network->name, 'cidr' => $cidr], $actor);

        return $network;
    }

    /** Usunięcie pustej sieci — mostek na węźle znika sam, gdy odłączy się od niego ostatnia karta. */
    public function delete(PrivateNetwork $network, ?User $actor = null): void
    {
        if ($network->nics()->exists()) {
            throw new \DomainException(__('W sieci są jeszcze maszyny — najpierw odłącz ich karty.'));
        }
        AuditLog::record('private_network.deleted', $network, ['name' => $network->name], $actor);
        $network->delete();
    }

    public function attach(Server $server, PrivateNetwork $network, ?string $address = null, ?User $actor = null): ServerJob
    {
        $this->assertAttachable($server, $network);

        $nic = DB::transaction(function () use ($server, $network, $address) {
            $used = $server->nics()->lockForUpdate()->pluck('slot')->all();
            $limit = (int) config('virthub.private_networks.nics_per_server', 4);
            if (count($used) >= $limit) {
                throw new \DomainException(__('Maszyna ma już maksymalną liczbę kart dodatkowych (:limit).', ['limit' => $limit]));
            }
            $slot = min(array_diff(range(1, 7), $used));

            return ServerNic::create([
                'server_id' => $server->id,
                'private_network_id' => $network->id,
                'slot' => $slot,
                'address' => $address !== null && $address !== '' ? $this->validateAddress($network, $address) : $this->nextAddress($network),
            ]);
        });
        AuditLog::record('server.nic_attached', $server, ['network' => $network->name, 'address' => $nic->address, 'slot' => $nic->slot], $actor);

        return $this->provisioner->syncPrivateNics($server, $actor);
    }

    public function detach(ServerNic $nic, ?User $actor = null): ServerJob
    {
        $server = $nic->server;
        if ($server->state->isTransitioning()) {
            throw new \DomainException(__('Trwa operacja na maszynie — spróbuj za chwilę.'));
        }
        AuditLog::record('server.nic_detached', $server, ['network' => $nic->network?->name, 'address' => $nic->address], $actor);
        $nic->delete();

        return $this->provisioner->syncPrivateNics($server, $actor);
    }

    /** @return list<array<string, mixed>> */
    public static function payload(Server $server): array
    {
        if ($server->isContainer()) {
            return [];
        }

        return $server->nics()->with('network')->get()->map->toAgentPayload()->values()->all();
    }

    /** Te same warunki co przy podłączaniu, zanim powstanie nowa sieć dla maszyny. */
    public function assertAttachableToNew(Server $server): void
    {
        if ($server->isContainer()) {
            throw new \DomainException(__('Sieci prywatne są dostępne tylko dla maszyn KVM.'));
        }
        if ($server->hypervisor === null || $server->agent_uuid === null || $server->state->isTransitioning()) {
            throw new \DomainException(__('Maszyna nie jest gotowa — spróbuj, gdy zakończy bieżącą operację.'));
        }
        $limit = (int) config('virthub.private_networks.nics_per_server', 4);
        if ($server->nics()->count() >= $limit) {
            throw new \DomainException(__('Maszyna ma już maksymalną liczbę kart dodatkowych (:limit).', ['limit' => $limit]));
        }
    }

    public function assertAttachable(Server $server, PrivateNetwork $network): void
    {
        if ($server->isContainer()) {
            throw new \DomainException(__('Sieci prywatne są dostępne tylko dla maszyn KVM.'));
        }
        if ($server->user_id !== $network->user_id) {
            throw new \DomainException(__('Sieć należy do innego klienta.'));
        }
        if ($server->hypervisor_id !== $network->hypervisor_id) {
            throw new \DomainException(__('Sieć prywatna działa na jednym węźle — maszyna jest na innym niż sieć :name.', ['name' => $network->name]));
        }
        if ($server->agent_uuid === null || $server->state->isTransitioning()) {
            throw new \DomainException(__('Maszyna nie jest gotowa — spróbuj, gdy zakończy bieżącą operację.'));
        }
        if ($server->nics()->where('private_network_id', $network->id)->exists()) {
            throw new \DomainException(__('Maszyna jest już w sieci :name.', ['name' => $network->name]));
        }
    }

    public function normaliseCidr(string $cidr): string
    {
        if (! preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($cidr), $m) || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \DomainException(__('Podaj sieć IPv4 w zapisie CIDR, np. 10.10.0.0/24.'));
        }
        $prefix = (int) $m[2];
        if ($prefix < 16 || $prefix > 29) {
            throw new \DomainException(__('Sieć prywatna musi mieć prefiks od /16 do /29.'));
        }
        $network = long2ip(ip2long($m[1]) & $this->mask($prefix));
        $inside = collect(self::PRIVATE_RANGES)->contains(function (string $range) use ($network, $prefix) {
            [$base, $bits] = explode('/', $range);

            return $prefix >= (int) $bits && (ip2long($network) & $this->mask((int) $bits)) === ip2long($base);
        });
        if (! $inside) {
            throw new \DomainException(__('Adresacja sieci prywatnej musi pochodzić z zakresów prywatnych (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16).'));
        }

        return $network.'/'.$prefix;
    }

    /** Propozycja adresacji: wolne 10.x.y.0/24 (inna niż pozostałe sieci klienta). */
    public function suggestCidr(User $owner): string
    {
        $taken = PrivateNetwork::query()->where('user_id', $owner->id)->pluck('cidr')->all();
        for ($i = 0; $i < 50; $i++) {
            $cidr = sprintf('10.%d.%d.0/24', random_int(16, 250), random_int(0, 255));
            if (! in_array($cidr, $taken, true)) {
                return $cidr;
            }
        }

        return '10.250.0.0/24';
    }

    private function validateAddress(PrivateNetwork $network, string $address): string
    {
        [$first, $last] = $this->hostRange($network);
        $long = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? ip2long($address) : false;
        if ($long === false || $long < $first || $long > $last) {
            throw new \DomainException(__('Adres :address nie należy do sieci :cidr.', ['address' => $address, 'cidr' => $network->cidr]));
        }
        if ($network->nics()->where('address', $address)->exists()) {
            throw new \DomainException(__('Adres :address jest już zajęty w tej sieci.', ['address' => $address]));
        }

        return $address;
    }

    private function nextAddress(PrivateNetwork $network): string
    {
        [$first, $last] = $this->hostRange($network);
        $used = array_flip(array_map('ip2long', $network->nics()->whereNotNull('address')->pluck('address')->all()));
        for ($long = $first; $long <= $last; $long++) {
            if (! isset($used[$long])) {
                return long2ip($long);
            }
        }
        throw new \DomainException(__('W sieci :name nie ma wolnych adresów.', ['name' => $network->name]));
    }

    /** @return array{0: int, 1: int} pierwszy i ostatni adres hosta */
    private function hostRange(PrivateNetwork $network): array
    {
        [$base, $prefix] = explode('/', $network->cidr);
        $start = ip2long($base) & $this->mask((int) $prefix);
        $size = 2 ** (32 - (int) $prefix);

        return [$start + 1, $start + $size - 2];
    }

    private function mask(int $prefix): int
    {
        return $prefix === 0 ? 0 : (~0 << (32 - $prefix)) & 0xFFFFFFFF;
    }
}
