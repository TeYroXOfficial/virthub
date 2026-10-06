<?php

namespace App\Domain\External;

use App\Jobs\ProvisionExternalServerJob;
use App\Models\AuditLog;
use App\Models\ExternalServer;
use App\Models\ProviderAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Maszyny u dostawców zewnętrznych: zamówienie, stan, zasilanie, reinstalacja,
 * zawieszenie i usunięcie — przez sterownik dostawcy z addonu.
 */
class ExternalServerManager
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    public function driver(ExternalServer|ProviderAccount $target): ProviderDriver
    {
        return $this->registry->for($target instanceof ExternalServer ? $target->account : $target);
    }

    /**
     * Katalog dostawcy (lokalizacje, typy, systemy) — z pamięci podręcznej na 10 min,
     * bo formularz produktu i sklep pytają o niego często.
     *
     * @return array{locations:list<array>, plans:list<array>, images:list<array>}
     */
    public function catalog(ProviderAccount $account, bool $fresh = false): array
    {
        $key = 'provider-catalog.'.$account->id.'.'.$account->updated_at?->timestamp;
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 600, function () use ($account) {
            $driver = $this->driver($account);

            return ['locations' => $driver->locations(), 'plans' => $driver->plans(), 'images' => $driver->images()];
        });
    }

    /** @param  array{location:string, plan:string, cpu?:?int, ram_mb?:?int, disk_gb?:?int}  $spec */
    public function order(User $user, ProviderAccount $account, array $spec, string $image, ?string $imageName, string $hostname, array $sshKeys = [], ?User $actor = null): ExternalServer
    {
        if (! $account->is_active) {
            throw new \DomainException(__('Konto dostawcy jest wyłączone.'));
        }
        $this->driver($account); // brak sterownika (addon wyłączony) → błąd od razu, przed zapisaniem maszyny

        $server = ExternalServer::query()->create([
            'user_id' => $user->id,
            'provider_account_id' => $account->id,
            'name' => $hostname,
            'hostname' => $hostname,
            'location' => $spec['location'],
            'plan' => $spec['plan'],
            'cpu' => $spec['cpu'] ?? null,
            'ram_mb' => $spec['ram_mb'] ?? null,
            'disk_gb' => $spec['disk_gb'] ?? null,
            'image' => $image,
            'image_name' => $imageName,
            'status' => ExternalServer::PENDING,
        ]);
        AuditLog::record('external.ordered', $server, ['account' => $account->name, 'plan' => $spec['plan'], 'location' => $spec['location']], $actor);
        ProvisionExternalServerJob::dispatch($server->id, $sshKeys);

        return $server;
    }

    /** Wysyła zamówienie do dostawcy (z joba). */
    public function provision(ExternalServer $server, array $sshKeys = []): void
    {
        if ($server->remote_id !== null || $server->trashed()) {
            return;
        }
        try {
            $id = $this->driver($server)->create(new CreateRequest(
                name: $server->hostname,
                location: $server->location,
                plan: $server->plan,
                image: $server->image,
                cpu: $server->cpu,
                ramMb: $server->ram_mb,
                diskGb: $server->disk_gb,
                sshKeys: $sshKeys,
                reference: 'virthub:'.$server->uuid,
            ));
        } catch (Throwable $e) {
            $server->update(['status' => ExternalServer::ERROR, 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
            AuditLog::record('external.provision_failed', $server, ['error' => $e->getMessage()]);

            throw $e;
        }
        $server->update(['remote_id' => $id, 'status' => ExternalServer::BUILDING, 'last_error' => null]);
        $this->sync($server);
    }

    /** Odczytuje stan u dostawcy. Błąd sieci nie zmienia stanu (spróbujemy później). */
    public function sync(ExternalServer $server): ExternalServer
    {
        if ($server->remote_id === null) {
            return $server;
        }
        try {
            $remote = $this->driver($server)->get($server->remote_id);
        } catch (Throwable $e) {
            $server->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);

            return $server;
        }
        $status = match ($remote->status) {
            RemoteServer::RUNNING => ExternalServer::RUNNING,
            RemoteServer::STOPPED, RemoteServer::SUSPENDED => ExternalServer::STOPPED,
            RemoteServer::BUILDING => ExternalServer::BUILDING,
            RemoteServer::BUSY => ExternalServer::BUSY,
            RemoteServer::DELETED => ExternalServer::DELETED,
            default => ExternalServer::ERROR,
        };
        $server->fill([
            'status' => $status,
            'ipv4' => $remote->ipv4 ?? $server->ipv4,
            'ipv6' => $remote->ipv6 ?? $server->ipv6,
            'cpu' => $remote->cpu ?? $server->cpu,
            'ram_mb' => $remote->ramMb ?? $server->ram_mb,
            'disk_gb' => $remote->diskGb ?? $server->disk_gb,
            'synced_at' => now(),
            'last_error' => null,
        ]);
        // Hasło dostawca podaje po utworzeniu i reinstalacji — zachowujemy ostatnie.
        if ($remote->password) {
            $server->password = $remote->password;
        }
        $server->save();

        return $server;
    }

    /** Maszyny w trakcie zmian — harmonogram co minutę. */
    public function syncTransitional(): int
    {
        $count = 0;
        ExternalServer::query()->whereIn('status', ExternalServer::TRANSITIONAL)->whereNotNull('remote_id')
            ->with('account')->limit(200)->get()
            ->each(function (ExternalServer $server) use (&$count) {
                $this->sync($server);
                $count++;
            });

        return $count;
    }

    /** @param  'start'|'stop'|'reboot'|'kill'  $action */
    public function power(ExternalServer $server, string $action, ?User $actor = null): void
    {
        $this->assertManageable($server);
        $this->driver($server)->power((string) $server->remote_id, $action);
        $server->update(['status' => ExternalServer::BUSY]);
        AuditLog::record('external.power', $server, ['action' => $action], $actor);
    }

    public function reinstall(ExternalServer $server, string $image, ?string $imageName, ?User $actor = null): void
    {
        $this->assertManageable($server);
        $this->driver($server)->reinstall((string) $server->remote_id, $image);
        $server->update(['image' => $image, 'image_name' => $imageName, 'status' => ExternalServer::BUSY, 'password' => null]);
        AuditLog::record('external.reinstall', $server, ['image' => $imageName ?? $image], $actor);
    }

    public function console(ExternalServer $server, ?User $actor = null): string
    {
        $this->assertManageable($server);
        $url = $this->driver($server)->console((string) $server->remote_id);
        AuditLog::record('external.console', $server, [], $actor);

        return $url;
    }

    public function rdns(ExternalServer $server, string $ip, ?string $hostname, ?User $actor = null): void
    {
        $this->assertManageable($server);
        if (! in_array($ip, array_filter([$server->ipv4, $server->ipv6]), true)) {
            throw new \DomainException(__('Ten adres nie należy do serwera.'));
        }
        $this->driver($server)->setRdns((string) $server->remote_id, $ip, $hostname);
        AuditLog::record('external.rdns', $server, ['ip' => $ip, 'hostname' => $hostname], $actor);
    }

    public function rename(ExternalServer $server, string $name, ?User $actor = null): void
    {
        $server->update(['name' => $name]);
        AuditLog::record('external.renamed', $server, ['name' => $name], $actor);
    }

    /** Zawieszenie (billing/administrator): maszyna zatrzymana, klient bez akcji. */
    public function suspend(ExternalServer $server, ?User $actor = null): void
    {
        $server->update(['suspended_at' => now()]);
        if ($server->remote_id !== null && $server->status !== ExternalServer::DELETED) {
            $this->driver($server)->power($server->remote_id, 'stop');
            $server->update(['status' => ExternalServer::BUSY]);
        }
        AuditLog::record('external.suspended', $server, [], $actor);
    }

    public function unsuspend(ExternalServer $server, ?User $actor = null): void
    {
        $server->update(['suspended_at' => null]);
        if ($server->remote_id !== null && $server->status !== ExternalServer::DELETED) {
            $this->driver($server)->power($server->remote_id, 'start');
            $server->update(['status' => ExternalServer::BUSY]);
        }
        AuditLog::record('external.unsuspended', $server, [], $actor);
    }

    /** Usuwa maszynę u dostawcy i w panelu. Już nieistniejąca u dostawcy — tylko w panelu. */
    public function destroy(ExternalServer $server, ?User $actor = null): void
    {
        if ($server->remote_id !== null && $server->status !== ExternalServer::DELETED) {
            try {
                $this->driver($server)->destroy($server->remote_id);
            } catch (ProviderException $e) {
                $remote = rescue(fn () => $this->driver($server)->get((string) $server->remote_id), null, false);
                if ($remote === null || $remote->status !== RemoteServer::DELETED) {
                    throw $e;
                }
            }
        }
        $server->update(['status' => ExternalServer::DELETED]);
        $server->delete();
        AuditLog::record('external.deleted', $server, [], $actor);
    }

    private function assertManageable(ExternalServer $server): void
    {
        if ($server->isSuspended()) {
            throw new \DomainException(__('Serwer jest zawieszony.'));
        }
        if ($server->remote_id === null || in_array($server->status, [ExternalServer::PENDING, ExternalServer::DELETED], true)) {
            throw new \DomainException(__('Serwer nie jest jeszcze gotowy.'));
        }
    }
}
