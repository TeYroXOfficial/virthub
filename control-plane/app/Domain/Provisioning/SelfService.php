<?php

namespace App\Domain\Provisioning;

use App\Domain\Billing\Billing;
use App\Domain\Billing\InsufficientFunds;
use App\Domain\Billing\Money;
use App\Domain\Billing\Wallet;
use App\Models\AuditLog;
use App\Models\HypervisorGroup;
use App\Models\OsTemplate;
use App\Models\ResourcePack;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Samoobsługa: klient buduje i usuwa maszyny w ramach pakietu zasobów.
 *
 * Maszyna dostaje sieć, adresy i transfer z pakietu VPS przypiętego do pakietu
 * zasobów, a CPU/RAM/dysk wybrane przez klienta. Stawki godzinowe pobierane są
 * z portfela; brak środków zawiesza maszyny pakietu do doładowania.
 */
class SelfService
{
    public const MIN_RAM_MB = 512;

    public function __construct(
        private readonly ServerProvisioner $provisioner,
        private readonly Wallet $wallet,
    ) {}

    /** @param  list<string>  $sshKeys */
    public function build(ResourcePack $pack, OsTemplate $template, string $hostname, int $vcpu, int $ramMb, int $diskGb, array $sshKeys = [], ?HypervisorGroup $location = null, ?User $actor = null): Server
    {
        return Cache::lock('resource-pack:'.$pack->id, 30)->block(10, function () use ($pack, $template, $hostname, $vcpu, $ramMb, $diskGb, $sshKeys, $location, $actor) {
            $pack->refresh();
            $this->assertCanBuild($pack, $template, $vcpu, $ramMb, $diskGb);

            $base = $pack->package;
            $package = $base->replicate();
            $package->forceFill(['vcpu' => $vcpu, 'ram_mb' => $ramMb, 'disk_gb' => $diskGb, 'cpu_limit_percent' => null, 'pci_count' => 0]);
            $package->id = $base->id;

            $server = $this->provisioner->order(
                user: $pack->user,
                package: $package,
                template: $template,
                hostname: $hostname,
                sshKeys: $sshKeys,
                location: $location,
            );
            $server->forceFill(['resource_pack_id' => $pack->id])->save();
            AuditLog::record('self_service.built', $server, ['pack' => $pack->name, 'vcpu' => $vcpu, 'ram_mb' => $ramMb, 'disk_gb' => $diskGb], $actor ?? $pack->user);

            return $server;
        });
    }

    public function assertCanBuild(ResourcePack $pack, OsTemplate $template, int $vcpu, int $ramMb, int $diskGb): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['pack' => $message]);
        if (! $pack->is_active) {
            $fail(__('Pakiet zasobów jest wyłączony.'));
        }
        if ($pack->suspended_at !== null) {
            $fail(__('Pakiet zasobów jest zawieszony z powodu braku środków — doładuj portfel.'));
        }
        if ($vcpu < 1 || $ramMb < self::MIN_RAM_MB || $diskGb < max(1, (int) $template->min_disk_gb)) {
            $fail(__('Minimum: 1 vCPU, :ram MB RAM i :disk GB dysku dla tego systemu.', ['ram' => self::MIN_RAM_MB, 'disk' => $template->min_disk_gb]));
        }
        $used = $pack->used();
        if ($used['servers'] >= $pack->max_servers) {
            $fail(trans_choice('Pakiet pozwala na :count maszynę.|Pakiet pozwala na :count maszyny.|Pakiet pozwala na :count maszyn.', $pack->max_servers));
        }
        if ($pack->vcpu < $used['vcpu'] + $vcpu || $pack->ram_mb < $used['ram_mb'] + $ramMb || $pack->disk_gb < $used['disk_gb'] + $diskGb) {
            $fail(__('Za mało wolnych zasobów w pakiecie: zostało :vcpu vCPU, :ram MB RAM, :disk GB dysku.', [
                'vcpu' => $pack->vcpu - $used['vcpu'], 'ram' => $pack->ram_mb - $used['ram_mb'], 'disk' => $pack->disk_gb - $used['disk_gb'],
            ]));
        }
        if (Billing::enabled() && $pack->hasPricing()) {
            $cost = $pack->hourlyCost($vcpu, $ramMb, $diskGb);
            if ($this->wallet->balance($pack->user) < $cost) {
                $fail(__('Za mało środków w portfelu — godzina tej maszyny kosztuje :cost.', ['cost' => Money::format($cost)]));
            }
        }
    }

    /** Usunięcie z samoobsługi — tylko maszyny z pakietu (zwykłe są chronione). */
    public function destroy(Server $server, User $actor): void
    {
        if (! $server->isSelfService()) {
            throw ValidationException::withMessages(['pack' => __('Tę maszynę można usunąć tylko przez obsługę — nie pochodzi z samoobsługi.')]);
        }
        $this->provisioner->destroy($server, $actor);
        AuditLog::record('self_service.destroyed', $server, ['hostname' => $server->hostname], $actor);
    }

    /** Rozliczenie godzinowe (harmonogram co godzinę). Zwraca liczbę pobranych godzin. */
    public function bill(): int
    {
        if (! Billing::enabled()) {
            return 0;
        }
        $charged = 0;
        foreach (ResourcePack::query()->where('is_active', true)->with('user')->get() as $pack) {
            try {
                $charged += $this->billPack($pack);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $charged;
    }

    public function billPack(ResourcePack $pack): int
    {
        if (! $pack->hasPricing()) {
            return 0;
        }
        $servers = $pack->servers()->get();
        $cost = $servers->sum(fn (Server $s) => $pack->hourlyCost($s->vcpu, $s->ram_mb, $s->disk_gb));

        if ($pack->suspended_at !== null) {
            if ($cost > 0 && $this->wallet->balance($pack->user) >= $cost) {
                $this->resume($pack, $servers);
            }

            return 0;
        }
        if ($pack->billed_until === null) {
            $pack->forceFill(['billed_until' => now()])->save();

            return 0;
        }

        $hours = 0;
        while ($pack->billed_until->copy()->addHour()->lte(now()) && $hours < 24) {
            if ($cost > 0) {
                try {
                    $this->wallet->debit($pack->user, (int) $cost, 'usage', __('Samoobsługa :name: :count maszyn × 1 h', ['name' => $pack->name, 'count' => $servers->count()]));
                } catch (InsufficientFunds) {
                    $this->suspend($pack, $servers);

                    return $hours;
                }
            }
            $pack->billed_until = $pack->billed_until->copy()->addHour();
            $pack->save();
            $hours++;
        }

        return $hours;
    }

    private function suspend(ResourcePack $pack, $servers): void
    {
        $pack->forceFill(['suspended_at' => now()])->save();
        foreach ($servers as $server) {
            if (! $server->isSuspended()) {
                $this->provisioner->suspend($server, __('Brak środków w portfelu (samoobsługa).'));
            }
        }
        AuditLog::record('self_service.suspended', $pack, ['servers' => $servers->count()]);
    }

    private function resume(ResourcePack $pack, $servers): void
    {
        $pack->forceFill(['suspended_at' => null, 'billed_until' => now()])->save();
        foreach ($servers as $server) {
            if ($server->isSuspended()) {
                $this->provisioner->unsuspend($server);
            }
        }
        AuditLog::record('self_service.resumed', $pack, ['servers' => $servers->count()]);
    }
}
