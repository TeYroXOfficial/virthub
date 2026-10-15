<?php

namespace App\Domain\Provisioning;

use App\Domain\Agent\ServerPayload;
use App\Domain\Webhooks\Webhooks;
use App\Enums\ServerState;
use App\Enums\Virtualization;
use App\Jobs\MigrateServerJob;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Migracje maszyn KVM między hypervisorami.
 *
 * cold — źródło zatrzymuje maszynę i wystawia spłaszczony dysk, cel pobiera go
 *        bezpośrednio (sha256), zakłada domenę i uruchamia ją, jeśli działała;
 * live — cel przygotowuje sieć i pusty dysk, źródło przenosi działającą
 *        domenę przez libvirt z kopiowaniem dysku (wymaga połączenia libvirt
 *        między węzłami, VH_MIGRATION_URI).
 *
 * Zasoby celu są rezerwowane od razu (jak przy zamówieniu), źródła zwalniane
 * po sukcesie. Adresy IP zostają — dlatego ich pule muszą obejmować cel.
 */
class Migrations
{
    public const MODES = ['cold', 'live'];

    public function start(Server $server, Hypervisor $destination, string $mode = 'cold', ?User $actor = null): ServerJob
    {
        $this->assertMigratable($server, $destination, $mode);

        $job = DB::transaction(function () use ($server, $destination, $mode, $actor) {
            $this->adjustUsage($destination, $server, +1);
            $wasRunning = $server->state === ServerState::Running;
            $server->markState(ServerState::Migrating, __('Przenoszenie na węzeł :name.', ['name' => $destination->name]));

            return ServerJob::create([
                'server_id' => $server->id,
                'hypervisor_id' => $server->hypervisor_id,
                'user_id' => $actor?->id,
                'action' => 'migrate',
                'status' => ServerJob::STATUS_QUEUED,
                'payload' => ['destination_id' => $destination->id, 'source_id' => $server->hypervisor_id, 'mode' => $mode,
                    'phase' => 'start', 'was_running' => $wasRunning],
            ]);
        });
        AuditLog::record('server.migrate_started', $server, ['to' => $destination->name, 'mode' => $mode], $actor);
        MigrateServerJob::dispatch($job->id);

        return $job;
    }

    public function assertMigratable(Server $server, Hypervisor $destination, string $mode): void
    {
        $fail = fn (string $message) => throw new \DomainException($message);
        if (! in_array($mode, self::MODES, true)) {
            $fail(__('Nieznany tryb migracji.'));
        }
        if ($server->isContainer()) {
            $fail(__('Migracja działa dla maszyn KVM.'));
        }
        if ($server->hypervisor_id === $destination->id) {
            $fail(__('Maszyna już jest na tym węźle.'));
        }
        if ($server->agent_uuid === null || $server->hypervisor === null) {
            $fail(__('Maszyna nie istnieje jeszcze na żadnym węźle.'));
        }
        if ($server->isSuspended() || ! $server->state->acceptsCommands()) {
            $fail(__('Na maszynie trwa operacja albo jest zawieszona — spróbuj później.'));
        }
        if ($server->isElevated()) {
            $fail(__('Maszyna ma aktywny Elevate — najpierw zatwierdź albo odrzuć zmiany dysku.'));
        }
        if ($server->pciAssets()->exists()) {
            $fail(__('Maszyna ma przypisane urządzenia PCI — odepnij je przed migracją.'));
        }
        if ($server->disks()->exists()) {
            $fail(__('Maszyna ma dodatkowe dyski — migracja przenosi tylko dysk główny. Usuń dodatkowe dyski albo przenieś dane przed migracją.'));
        }
        if ($server->nics()->exists()) {
            $fail(__('Maszyna jest w sieci prywatnej węzła — odłącz jej karty prywatne przed migracją.'));
        }
        if ($server->datastore?->isCeph()) {
            $fail(__('Dysk maszyny jest w Cephie — migracja z kopiowaniem dysku obsługuje magazyny katalogowe.'));
        }
        if ($mode === 'live' && $server->state !== ServerState::Running) {
            $fail(__('Migracja na żywo dotyczy działającej maszyny — zatrzymaną przenieś na zimno.'));
        }
        if ($destination->virtualization !== Virtualization::Kvm || ! Hypervisor::query()->available()->whereKey($destination->id)->exists()) {
            $fail(__('Węzeł :name nie przyjmuje teraz maszyn KVM.', ['name' => $destination->name]));
        }
        if (! $destination->hasCapacityFor($server->vcpu, $server->ram_mb, $server->disk_gb)) {
            $fail(__('Węzeł :name nie ma wolnych zasobów na tę maszynę.', ['name' => $destination->name]));
        }
        $poolIds = $server->ipAddresses()->pluck('ip_pool_id')->unique()->values();
        $usable = IpPool::query()->usableBy($destination)->whereIn('id', $poolIds)->count();
        if ($usable !== $poolIds->count()) {
            $fail(__('Adresy IP maszyny pochodzą z puli przypisanej tylko do węzła źródłowego. Przypisz pulę do grupy obu węzłów albo zmień adresy przed migracją.'));
        }
    }

    /** Ładunek dla węzła docelowego — magazyn katalogowy tylko, jeśli jest tam przypięty. */
    public function payloadFor(Server $server, Hypervisor $destination): array
    {
        $payload = ServerPayload::forCreate($server);
        if ($server->datastore && ! $server->datastore->hypervisors()->whereKey($destination->id)->exists()) {
            unset($payload['storage']);
        }
        unset($payload['root_password'], $payload['ssh_keys']);

        return $payload;
    }

    /** Sukces: maszyna działa na nowym węźle. */
    public function finish(ServerJob $job, array $result): void
    {
        $server = $job->server;
        $payload = $job->payload;
        $source = Hypervisor::find($payload['source_id']);
        $destination = Hypervisor::findOrFail($payload['destination_id']);

        DB::transaction(function () use ($server, $source, $destination, $payload, $result) {
            if ($source) {
                $this->adjustUsage($source, $server, -1);
            }
            $keepsDatastore = $server->datastore && $server->datastore->hypervisors()->whereKey($destination->id)->exists();
            $running = $payload['mode'] === 'live' || ($payload['was_running'] ?? false);
            $server->forceFill(array_filter([
                'hypervisor_id' => $destination->id,
                'datastore_id' => $keepsDatastore ? $server->datastore_id : null,
                'agent_uuid' => $result['uuid'] ?? $server->agent_uuid,
                'vnc_port' => $result['vnc_port'] ?? null,
                'vnc_password' => $result['vnc_password'] ?? null,
            ], fn ($v, $k) => $v !== null || $k === 'datastore_id', ARRAY_FILTER_USE_BOTH))->save();
            $server->markState($running ? ServerState::Running : ServerState::Stopped);
        });

        $job->markDone(['to' => $destination->name, 'mode' => $payload['mode']]);
        AuditLog::record('server.migrated', $server, ['from' => $source?->name, 'to' => $destination->name, 'mode' => $payload['mode']]);
        $webhooks = app(Webhooks::class);
        $webhooks->dispatch('server.migrated', $webhooks->describe($server->fresh()) + ['from_hypervisor' => $source?->name, 'to_hypervisor' => $destination->name, 'mode' => $payload['mode']]);
    }

    /** Porażka: maszyna zostaje na źródle, rezerwacja celu wraca. */
    public function fail(ServerJob $job, string $error): void
    {
        $server = $job->server;
        $destination = Hypervisor::find($job->payload['destination_id']);
        DB::transaction(function () use ($server, $destination) {
            if ($destination) {
                $this->adjustUsage($destination, $server, -1);
            }
        });
        $running = ($job->payload['was_running'] ?? false) && ($job->payload['restarted'] ?? $job->payload['mode'] === 'live');
        $server->markState($running ? ServerState::Running : ServerState::Stopped, __('Migracja nie powiodła się: :error', ['error' => $error]));
        $job->markFailed($error);
        AuditLog::record('server.migrate_failed', $server, ['error' => $error]);
    }

    private function adjustUsage(Hypervisor $node, Server $server, int $sign): void
    {
        $node = Hypervisor::query()->lockForUpdate()->findOrFail($node->id);
        $node->forceFill([
            'cpu_cores_used' => max(0, $node->cpu_cores_used + $sign * $server->vcpu),
            'ram_mb_used' => max(0, $node->ram_mb_used + $sign * $server->ram_mb),
            'disk_gb_used' => max(0, $node->disk_gb_used + $sign * $server->disk_gb),
        ])->save();
    }
}
