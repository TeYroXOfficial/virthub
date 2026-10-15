<?php

namespace App\Domain\Provisioning;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Agent\ServerPayload;
use App\Domain\Network\PrivateNetworks;
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
use Illuminate\Support\Collection;
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
 *
 * Każdy etap zapisuje się w payload.steps (początek, koniec), bieżący postęp
 * agenta w payload.progress. Trwającą migrację można anulować; nieudaną
 * wznowić (na zimno od pobierania, jeśli eksport na źródle przetrwał) albo
 * cofnąć — sprzątnięcie pozostałości na celu i unieważnienie eksportu.
 */
class Migrations
{
    public const MODES = ['cold', 'live'];

    /** Etapy i ich udział w całości postępu (%), dla paska postępu. */
    public const PHASES = [
        'cold' => ['start' => [0, 0], 'exporting' => [0, 45], 'importing' => [45, 100]],
        'live' => ['start' => [0, 0], 'preparing' => [0, 10], 'migrating' => [10, 100]],
    ];

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
                    'phase' => 'start', 'was_running' => $wasRunning, 'steps' => []],
            ]);
        });
        AuditLog::record('server.migrate_started', $server, ['to' => $destination->name, 'mode' => $mode], $actor);
        MigrateServerJob::dispatch($job->id);

        return $job;
    }

    /**
     * Węzły KVM poza bieżącym z oceną: można przenieść (na zimno / na żywo) albo dlaczego nie.
     *
     * @return Collection<int, array{node: Hypervisor, cold: ?string, live: ?string}>
     */
    public function candidates(Server $server): Collection
    {
        return Hypervisor::query()->where('virtualization', Virtualization::Kvm)->whereKeyNot($server->hypervisor_id)->orderBy('name')->get()
            ->map(function (Hypervisor $node) use ($server) {
                $reason = fn (string $mode) => rescue(fn () => $this->assertMigratable($server, $node, $mode), fn (\DomainException $e) => $e->getMessage(), false);

                return ['node' => $node, 'cold' => $reason('cold'), 'live' => $reason('live')];
            })
            ->sortBy(fn (array $c) => [$c['cold'] !== null, $c['node']->name])->values();
    }

    /** Ostatnia migracja maszyny (do karty na stronie maszyny). */
    public function latest(Server $server): ?ServerJob
    {
        return $server->jobs()->where('action', 'migrate')->latest('id')->first();
    }

    /** Postęp całości (%) z etapu i postępu zadania agenta. */
    public static function overall(ServerJob $job): int
    {
        if ($job->status === ServerJob::STATUS_DONE) {
            return 100;
        }
        $p = $job->payload;
        [$from, $to] = self::PHASES[$p['mode'] ?? 'cold'][$p['phase'] ?? 'start'] ?? [0, 0];
        $agent = (int) ($p['progress']['percent'] ?? 0);

        return (int) round($from + ($to - $from) * min(100, max(0, $agent)) / 100);
    }

    /** Anulowanie trwającej migracji — zadanie w tle doprowadza je do bezpiecznego końca. */
    public function cancel(ServerJob $job, ?User $actor = null): void
    {
        if ($job->action !== 'migrate' || $job->isFinished()) {
            throw new \DomainException(__('Ta migracja już się zakończyła.'));
        }
        $job->forceFill(['payload' => array_merge($job->payload, ['cancel' => true])])->save();
        AuditLog::record('server.migrate_cancel_requested', $job->server, ['phase' => $job->payload['phase']], $actor);
        MigrateServerJob::dispatch($job->id);
    }

    /** Wznowienie nieudanej migracji: na zimno od pobrania, gdy eksport przetrwał; inaczej od początku. */
    public function resume(ServerJob $failed, ?User $actor = null): ServerJob
    {
        $this->assertRecoverable($failed);
        $server = $failed->server;
        $p = $failed->payload;
        $destination = Hypervisor::findOrFail($p['destination_id']);
        if (($p['resumable'] ?? null) !== 'importing' || empty($p['export']['token'])) {
            $failed->forceFill(['payload' => $p + ['resumed_at' => now()->toIso8601String()]])->save();

            return $this->start($server, $destination, $p['mode'], $actor);
        }
        $this->assertMigratable($server, $destination, 'cold');
        $job = DB::transaction(function () use ($server, $destination, $p, $actor) {
            $this->adjustUsage($destination, $server, +1);
            $server->markState(ServerState::Migrating, __('Wznowienie przenoszenia na węzeł :name.', ['name' => $destination->name]));

            return ServerJob::create([
                'server_id' => $server->id, 'hypervisor_id' => $server->hypervisor_id, 'user_id' => $actor?->id,
                'action' => 'migrate', 'status' => ServerJob::STATUS_QUEUED,
                'payload' => ['destination_id' => $destination->id, 'source_id' => $server->hypervisor_id, 'mode' => 'cold',
                    'phase' => 'resume-import', 'was_running' => (bool) ($p['was_running'] ?? false), 'export' => $p['export'],
                    'token' => $p['export']['token'], 'steps' => [], 'resumed_from' => $p['phase'] ?? null],
            ]);
        });
        $failed->forceFill(['payload' => $p + ['resumed_at' => now()->toIso8601String()]])->save();
        AuditLog::record('server.migrate_resumed', $server, ['to' => $destination->name], $actor);
        MigrateServerJob::dispatch($job->id);

        return $job;
    }

    /** Cofnięcie nieudanej migracji: pozostałości na celu znikają, eksport na źródle też. */
    public function revert(ServerJob $failed, ?User $actor = null): void
    {
        $this->assertRecoverable($failed);
        $p = $failed->payload;
        $server = $failed->server;
        $errors = [];
        if ($destination = Hypervisor::find($p['destination_id'])) {
            try {
                (new AgentClient($destination))->cleanupIncoming($server->id);
            } catch (AgentException $e) {
                $errors[] = $destination->name.': '.$e->getMessage();
            }
        }
        $token = $p['export']['token'] ?? $p['token'] ?? null;
        if ($token && ($source = Hypervisor::find($p['source_id']))) {
            try {
                (new AgentClient($source))->revokeTransfer($token);
            } catch (AgentException) {
                // Wygasły albo już usunięty — bez znaczenia.
            }
        }
        if ($errors !== []) {
            throw new \DomainException(__('Nie udało się posprzątać węzła docelowego: :error', ['error' => implode('; ', $errors)]));
        }
        $failed->forceFill(['payload' => array_merge($p, ['reverted_at' => now()->toIso8601String(), 'resumable' => null])])->save();
        AuditLog::record('server.migrate_reverted', $server, ['destination_id' => $p['destination_id']], $actor);
    }

    private function assertRecoverable(ServerJob $job): void
    {
        $p = $job->payload;
        if ($job->action !== 'migrate' || $job->status !== ServerJob::STATUS_FAILED) {
            throw new \DomainException(__('Wznowić albo cofnąć można tylko nieudaną migrację.'));
        }
        if (! empty($p['reverted_at']) || ! empty($p['resumed_at'])) {
            throw new \DomainException(__('Ta migracja została już wznowiona albo cofnięta.'));
        }
        $server = $job->server;
        if ($server === null || $server->hypervisor_id !== ($p['source_id'] ?? null)) {
            throw new \DomainException(__('Maszyna nie jest już na węźle źródłowym tej migracji.'));
        }
        if ($this->latest($server)?->id !== $job->id) {
            throw new \DomainException(__('Po tej migracji była już kolejna.'));
        }
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
        if ($server->inRescue()) {
            $fail(__('Maszyna jest w trybie ratunkowym — najpierw go zakończ.'));
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
        $local = $server->nics()->with('network')->get()->first(fn ($nic) => ! $nic->network->spansGroup() || ! $nic->network->reachableFrom($destination));
        if ($local !== null) {
            $fail($local->network->spansGroup()
                ? __('Sieć prywatna :name obejmuje inną grupę węzłów albo węzeł docelowy nie ma adresu VXLAN — odłącz kartę przed migracją.', ['name' => $local->network->name])
                : __('Maszyna jest w sieci prywatnej węzła — odłącz jej karty prywatne przed migracją.'));
        }
        if ($server->datastore?->isFileBased() === false) {
            $fail(__('Dysk maszyny jest w magazynie blokowym (:type) — migracja z kopiowaniem dysku obsługuje magazyny plikowe.', ['type' => $server->datastore->typeLabel()]));
        }
        if ($mode === 'live' && $server->state !== ServerState::Running) {
            $fail(__('Migracja na żywo dotyczy działającej maszyny — zatrzymaną przenieś na zimno.'));
        }
        if ($destination->virtualization !== Virtualization::Kvm || ! Hypervisor::query()->available()->whereKey($destination->id)->exists()) {
            $fail(__('Węzeł :name nie przyjmuje teraz maszyn KVM.', ['name' => $destination->name]));
        }
        if ($destination->arch() !== $server->hypervisor->arch()) {
            $fail(__('Węzeł :name ma inną architekturę (:arch) — maszyny nie da się przenieść między x86 a ARM.', ['name' => $destination->name, 'arch' => $destination->arch()]));
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
        // Tunele VXLAN sieci prywatnych zestawiane z węzła docelowego.
        $nics = PrivateNetworks::payload($server, $destination);
        if ($nics !== []) {
            $payload['private_nics'] = $nics;
        }

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
        // Węzły sieci prywatnych dostają nową listę węzłów w tunelu.
        foreach ($server->nics()->with('network')->get() as $nic) {
            app(PrivateNetworks::class)->syncPeers($nic->network, $server->fresh());
        }
        AuditLog::record('server.migrated', $server, ['from' => $source?->name, 'to' => $destination->name, 'mode' => $payload['mode']]);
        $webhooks = app(Webhooks::class);
        $webhooks->dispatch('server.migrated', $webhooks->describe($server->fresh()) + ['from_hypervisor' => $source?->name, 'to_hypervisor' => $destination->name, 'mode' => $payload['mode']]);
    }

    /** Porażka: maszyna zostaje na źródle, rezerwacja celu wraca. */
    public function fail(ServerJob $job, string $error, bool $cancelled = false): void
    {
        $server = $job->server;
        $destination = Hypervisor::find($job->payload['destination_id']);
        DB::transaction(function () use ($server, $destination) {
            if ($destination) {
                $this->adjustUsage($destination, $server, -1);
            }
        });
        $running = ($job->payload['was_running'] ?? false) && ($job->payload['restarted'] ?? $job->payload['mode'] === 'live');
        $server->markState($running ? ServerState::Running : ServerState::Stopped, $cancelled ? __('Migracja anulowana.') : __('Migracja nie powiodła się: :error', ['error' => $error]));
        $job->markFailed($error);
        AuditLog::record($cancelled ? 'server.migrate_cancelled' : 'server.migrate_failed', $server, ['error' => $error]);
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
