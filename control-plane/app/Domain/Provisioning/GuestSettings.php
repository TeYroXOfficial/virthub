<?php

namespace App\Domain\Provisioning;

use App\Domain\Network\IpMath;
use App\Jobs\RunServerActionJob;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ustawienia, które klient zmienia sam: dysk konfiguracji (cloud-init) na czas
 * instalacji z ISO, nazwa hosta i wybór własnego adresu z puli IPv6.
 */
class GuestSettings
{
    public const HOSTNAME = '/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/';

    public function __construct(private readonly ServerProvisioner $provisioner, private readonly IpAllocator $ips) {}

    /** Odłączenie (false) albo podłączenie dysku cloud-init — działa od następnego uruchomienia. */
    public function cloudInit(Server $server, bool $enabled, ?User $actor = null): ServerJob
    {
        $this->assertReady($server);
        if ($server->isContainer()) {
            throw new \DomainException(__('Dysk konfiguracji dotyczy maszyn KVM.'));
        }

        return $this->job($server, 'seed', ['enabled' => $enabled], $actor, 'server.cloud_init_'.($enabled ? 'enabled' : 'disabled'));
    }

    /** Nowa nazwa hosta w panelu i — jeśli działa qemu-guest-agent — od razu w systemie. */
    public function hostname(Server $server, string $hostname, ?User $actor = null): ?ServerJob
    {
        $hostname = strtolower(trim($hostname));
        if (! preg_match(self::HOSTNAME, $hostname)) {
            throw new \DomainException(__('Nieprawidłowa nazwa hosta — dozwolone litery, cyfry, myślniki i kropki (np. web1.example.com).'));
        }
        $old = $server->hostname;
        $server->forceFill(['hostname' => $hostname])->save();
        AuditLog::record('server.hostname', $server, ['from' => $old, 'to' => $hostname], $actor);
        if ($server->isContainer() || $server->agent_uuid === null || $server->state->isTransitioning()) {
            return null;
        }

        return $this->job($server, 'hostname', ['hostname' => $hostname], $actor);
    }

    /** Zamiana adresu IPv6 maszyny na wybrany wolny adres z tej samej puli. */
    public function changeIpv6(Server $server, IpAddress $current, string $address, ?User $actor = null): ServerJob
    {
        $this->assertReady($server);
        if ($current->server_id !== $server->id || $current->version !== 6) {
            throw new \DomainException(__('To nie jest adres IPv6 tej maszyny.'));
        }
        $pool = $current->pool;
        if (IpMath::version($address) !== 6) {
            throw new \DomainException(__('Podaj adres IPv6.'));
        }
        $address = IpMath::normalize($address);
        if (! IpMath::contains($pool->cidr, $address) || IpMath::compare($address, $pool->firstAssignable()) < 0
            || IpMath::compare($address, $pool->lastAssignable()) > 0 || ($pool->gateway && IpMath::compare($address, $pool->gateway) === 0)) {
            throw new \DomainException(__('Adres :address nie należy do puli :cidr albo jest zarezerwowany.', ['address' => $address, 'cidr' => $pool->cidr]));
        }

        DB::transaction(function () use ($server, $current, $address, $pool) {
            $existing = IpAddress::query()->where('scope_key', $pool->scopeKey())->where('address', $address)->lockForUpdate()->first();
            if ($existing !== null && ($existing->server_id !== null || $existing->is_reserved || $existing->ip_pool_id !== $pool->id)) {
                throw new \DomainException(__('Adres :address jest zajęty.', ['address' => $address]));
            }
            $new = $existing ?? IpAddress::create([
                'ip_pool_id' => $pool->id, 'hypervisor_id' => $pool->hypervisor_id, 'address' => $address,
                'scope_key' => $pool->scopeKey(), 'version' => 6,
            ]);
            $new->forceFill([
                'server_id' => $server->id, 'hypervisor_id' => $server->hypervisor_id,
                'is_primary' => $current->is_primary, 'assigned_at' => now(),
            ])->save();
            $this->ips->release($current);
        });
        AuditLog::record('server.ipv6_changed', $server, ['from' => $current->address, 'to' => $address], $actor);

        return $this->provisioner->syncNetwork($server, $actor);
    }

    private function assertReady(Server $server): void
    {
        if ($server->isSuspended() || $server->agent_uuid === null || $server->state->isTransitioning()) {
            throw new \DomainException(__('Maszyna nie jest gotowa — spróbuj, gdy zakończy bieżącą operację.'));
        }
    }

    private function job(Server $server, string $action, array $payload, ?User $actor, ?string $event = null): ServerJob
    {
        $job = ServerJob::create([
            'server_id' => $server->id, 'hypervisor_id' => $server->hypervisor_id, 'user_id' => $actor?->id,
            'action' => $action, 'status' => ServerJob::STATUS_QUEUED, 'payload' => $payload,
        ]);
        if ($event) {
            AuditLog::record($event, $server, [], $actor);
        }
        RunServerActionJob::dispatch($job->id);

        return $job;
    }
}
