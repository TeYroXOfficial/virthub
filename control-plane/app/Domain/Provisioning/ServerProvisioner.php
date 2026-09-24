<?php

namespace App\Domain\Provisioning;

use App\Enums\ServerState;
use App\Jobs\ProvisionServerJob;
use App\Jobs\RunServerActionJob;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Hypervisor;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\User;
use App\Models\VpsPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Punkt wejścia dla wszystkich operacji na maszynach.
 *
 * Kontrolery i integracja billingowa wywołują wyłącznie te metody — nie
 * rozmawiają z agentem bezpośrednio. Dzięki temu rezerwacja zasobów, wpis do
 * audytu i kolejkowanie zadania dzieją się zawsze w komplecie, niezależnie od
 * tego, czy operację zlecił klient z panelu, czy webhook z systemu billingowego.
 */
class ServerProvisioner
{
    public function __construct(
        private readonly HypervisorSelector $selector,
        private readonly IpAllocator $ips,
    ) {}

    /**
     * Zamówienie nowej maszyny. Zwraca rekord w stanie „building" — właściwe
     * tworzenie dzieje się w kolejce, bo trwa dziesiątki sekund.
     *
     * @param  list<string>  $sshKeys
     *
     * @throws NoCapacityException|NoAddressesException
     */
    public function order(
        User $user,
        VpsPackage $package,
        OsTemplate $template,
        string $hostname,
        array $sshKeys = [],
        ?string $label = null,
        ?Hypervisor $preferred = null,
        ?string $billingReference = null,
    ): Server {
        if (! $template->isSelfService()) {
            throw new \InvalidArgumentException(
                "Szablon {$template->name} nie wspiera automatycznej instalacji."
            );
        }

        if ($package->disk_gb < $template->min_disk_gb) {
            throw new \InvalidArgumentException(
                "Pakiet {$package->name} ma {$package->disk_gb} GB dysku, a {$template->name} "
                ."wymaga co najmniej {$template->min_disk_gb} GB."
            );
        }

        $rootPassword = $this->generatePassword();

        $server = DB::transaction(function () use (
            $user, $package, $template, $hostname, $label, $preferred, $billingReference, $rootPassword
        ) {
            $server = new Server([
                'user_id' => $user->id,
                'vps_package_id' => $package->id,
                'os_template_id' => $template->id,
                'hostname' => $hostname,
                'label' => $label,
                // Parametry kopiujemy z pakietu — późniejsza zmiana cennika nie
                // może po cichu przestawić zasobów działającej maszyny.
                'vcpu' => $package->vcpu,
                'ram_mb' => $package->ram_mb,
                'disk_gb' => $package->disk_gb,
                'bandwidth_gb' => $package->bandwidth_gb,
                'billing_reference' => $billingReference,
            ]);
            $server->state = ServerState::Building;
            $server->root_password = $rootPassword;
            // Szablon decyduje o rodzaju maszyny: z obrazu kontenera powstaje
            // kontener, z obrazu dysku — maszyna wirtualna.
            $server->virtualization = $template->virtualization;
            $server->save();

            $hypervisor = $this->selector->reserve($server, $preferred);
            $this->ips->allocate($server, $hypervisor, $package->ip_count);

            return $server;
        });

        $job = $this->createJobRecord($server, 'create', $user, [
            'template' => $template->image_file,
            'ssh_keys_count' => count($sshKeys),
        ]);

        AuditLog::record('server.ordered', $server, [
            'package' => $package->slug,
            'template' => $template->name,
            'hypervisor_id' => $server->hypervisor_id,
        ], $user);

        ProvisionServerJob::dispatch($job->id, $sshKeys);

        return $server->refresh();
    }

    // --- operacje na istniejącej maszynie -----------------------------------

    public function power(Server $server, string $action, ?User $actor = null): ServerJob
    {
        $this->assertAcceptsCommands($server);

        $job = $this->createJobRecord($server, 'power', $actor, ['action' => $action]);
        AuditLog::record("server.power.{$action}", $server, [], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    public function rebuild(Server $server, OsTemplate $template, array $sshKeys = [], ?User $actor = null): ServerJob
    {
        $this->assertAcceptsCommands($server);

        if ($template->virtualization !== $server->virtualization) {
            throw new \DomainException(
                "Nie da się przebudować maszyny typu {$server->virtualization->shortLabel()} "
                ."z szablonu {$template->name} ({$template->virtualization->shortLabel()}). "
                .'Wybierz szablon tego samego typu.'
            );
        }

        $password = $this->generatePassword();
        $server->forceFill(['root_password' => $password])->save();
        $server->markState(ServerState::Rebuilding, 'Trwa ponowna instalacja systemu.');
        $server->template()->associate($template)->save();

        $job = $this->createJobRecord($server, 'rebuild', $actor, [
            'template' => $template->image_file,
            'hostname' => $server->hostname,
            'ssh_keys' => $sshKeys,
            'root_password' => $password,
        ]);

        AuditLog::record('server.rebuild', $server, ['template' => $template->name], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    public function resize(Server $server, VpsPackage $package, ?User $actor = null): ServerJob
    {
        $this->assertAcceptsCommands($server);

        if ($server->isRunning()) {
            throw new \DomainException(
                'Zmiana pakietu wymaga zatrzymanej maszyny. Zatrzymaj VPS i ponów operację.'
            );
        }

        if ($package->disk_gb < $server->disk_gb) {
            throw new \DomainException(
                "Nie da się zmniejszyć dysku z {$server->disk_gb} GB do {$package->disk_gb} GB — "
                .'wybierz pakiet z dyskiem nie mniejszym niż obecny.'
            );
        }

        // Różnicę zasobów księgujemy od razu, żeby równoległe zamówienie nie
        // zajęło miejsca, które ta maszyna właśnie ma dostać.
        $this->reserveDifference($server, $package);

        $job = $this->createJobRecord($server, 'resize', $actor, [
            'vcpu' => $package->vcpu,
            'ram_mb' => $package->ram_mb,
            'disk_gb' => $package->disk_gb,
            'package_id' => $package->id,
        ]);

        $server->markState(ServerState::Resizing, 'Trwa zmiana parametrów maszyny.');
        AuditLog::record('server.resize', $server, ['package' => $package->slug], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    public function destroy(Server $server, ?User $actor = null): ServerJob
    {
        $job = $this->createJobRecord($server, 'delete', $actor);

        $server->markState(ServerState::Deleting, 'Maszyna jest usuwana.');
        AuditLog::record('server.delete', $server, ['hostname' => $server->hostname], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    public function snapshot(Server $server, string $name, ?User $actor = null): ServerJob
    {
        $this->assertAcceptsCommands($server);

        $backup = Backup::create([
            'server_id' => $server->id,
            'name' => $name,
            'type' => 'snapshot',
            'status' => Backup::STATUS_CREATING,
        ]);

        $job = $this->createJobRecord($server, 'snapshot', $actor, [
            'name' => $name,
            'backup_id' => $backup->id,
        ]);

        AuditLog::record('server.snapshot', $server, ['name' => $name], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    public function restore(Server $server, Backup $backup, ?User $actor = null): ServerJob
    {
        $this->assertAcceptsCommands($server);

        if (! $backup->isRestorable()) {
            throw new \DomainException(
                "Kopia {$backup->name} nie jest gotowa do przywrócenia (status: {$backup->status})."
            );
        }

        $job = $this->createJobRecord($server, 'restore', $actor, [
            'name' => $backup->name,
            'backup_id' => $backup->id,
        ]);

        AuditLog::record('server.restore', $server, ['name' => $backup->name], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    public function syncNetwork(Server $server, ?User $actor = null): ServerJob
    {
        $job = $this->createJobRecord($server, 'network', $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    // --- zawieszanie (używane głównie przez integrację billingową) -----------

    public function suspend(Server $server, string $reason, ?User $actor = null): ServerJob
    {
        $server->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ])->save();

        $job = $this->createJobRecord($server, 'power', $actor, ['action' => 'stop']);
        AuditLog::record('server.suspend', $server, ['reason' => $reason], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    public function unsuspend(Server $server, ?User $actor = null): ServerJob
    {
        $server->forceFill([
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        $job = $this->createJobRecord($server, 'power', $actor, ['action' => 'start']);
        AuditLog::record('server.unsuspend', $server, [], $actor);
        RunServerActionJob::dispatch($job->id);

        return $job;
    }

    // --- pomocnicze ---------------------------------------------------------

    private function assertAcceptsCommands(Server $server): void
    {
        if ($server->isSuspended()) {
            throw new \DomainException(
                'Maszyna jest zawieszona. Skontaktuj się z obsługą, aby ją odwiesić.'
            );
        }

        if ($server->state->isTransitioning()) {
            throw new \DomainException(
                "Na maszynie trwa już operacja ({$server->state->label()}). "
                .'Poczekaj na jej zakończenie.'
            );
        }
    }

    private function reserveDifference(Server $server, VpsPackage $package): void
    {
        $hypervisor = $server->hypervisor;

        if ($hypervisor === null) {
            return;
        }

        $deltaCpu = $package->vcpu - $server->vcpu;
        $deltaRam = $package->ram_mb - $server->ram_mb;
        $deltaDisk = $package->disk_gb - $server->disk_gb;

        if (! $hypervisor->hasCapacityFor(max(0, $deltaCpu), max(0, $deltaRam), max(0, $deltaDisk))) {
            throw new NoCapacityException(
                "Hypervisor {$hypervisor->name} nie ma zasobów na powiększenie tej maszyny."
            );
        }

        DB::transaction(function () use ($hypervisor, $deltaCpu, $deltaRam, $deltaDisk) {
            $locked = Hypervisor::query()->lockForUpdate()->find($hypervisor->id);
            $locked->forceFill([
                'cpu_cores_used' => max(0, $locked->cpu_cores_used + $deltaCpu),
                'ram_mb_used' => max(0, $locked->ram_mb_used + $deltaRam),
                'disk_gb_used' => max(0, $locked->disk_gb_used + $deltaDisk),
            ])->save();
        });
    }

    private function createJobRecord(
        Server $server,
        string $action,
        ?User $actor = null,
        array $payload = [],
    ): ServerJob {
        return ServerJob::create([
            'server_id' => $server->id,
            'hypervisor_id' => $server->hypervisor_id,
            'user_id' => $actor?->id,
            'action' => $action,
            'status' => ServerJob::STATUS_QUEUED,
            'payload' => $payload ?: null,
        ]);
    }

    /**
     * Hasło startowe. Bez znaków, które w konsoli szeregowej albo przy
     * przepisywaniu z ekranu prowadzą do pomyłek (0/O, 1/l/I).
     */
    private function generatePassword(int $length = 20): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password.Str::upper(Str::random(2)).random_int(10, 99);
    }
}
