<?php

namespace App\Domain\Provisioning;

use App\Enums\Virtualization;
use App\Models\Hypervisor;
use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Dobór hypervisora pod nową maszynę i księgowanie zajętych zasobów.
 *
 * Strategia: bin-packing — wybieramy najbardziej zapełniony node, na którym
 * maszyna jeszcze się mieści. Odwrotność (najluźniejszy node) rozsypuje małe
 * VPS-y po całej flocie i po jakimś czasie nigdzie nie ma miejsca na duży.
 *
 * Rezerwacja idzie w transakcji z blokadą wiersza hypervisora, bo dwa
 * równoległe zamówienia potrafią zobaczyć to samo wolne miejsce i oba je zająć.
 */
class HypervisorSelector
{
    public function select(
        int $vcpu,
        int $ramMb,
        int $diskGb,
        Virtualization $virtualization = Virtualization::Kvm,
    ): ?Hypervisor {
        return Hypervisor::query()
            ->available()
            // Kontener nie stanie na węźle KVM (brak Incusa) ani maszyna
            // wirtualna na węźle bez VT-x.
            ->where('virtualization', $virtualization->value)
            ->get()
            ->filter(fn (Hypervisor $h) => $h->hasCapacityFor($vcpu, $ramMb, $diskGb))
            ->sortByDesc(fn (Hypervisor $h) => $h->utilisationPercent())
            ->first();
    }

    /**
     * Rezerwuje zasoby pod maszynę. Zwraca hypervisor, na którym maszyna
     * faktycznie się zmieściła.
     *
     * @throws NoCapacityException
     */
    public function reserve(Server $server, ?Hypervisor $preferred = null): Hypervisor
    {
        return DB::transaction(function () use ($server, $preferred) {
            $type = $server->virtualization ?? Virtualization::Kvm;

            if ($preferred !== null && $preferred->virtualization !== $type) {
                throw new NoCapacityException(
                    "Węzeł {$preferred->name} uruchamia {$preferred->virtualization->label()}, "
                    ."a zamówienie dotyczy: {$type->label()}."
                );
            }

            $candidate = $preferred ?? $this->select(
                $server->vcpu, $server->ram_mb, $server->disk_gb, $type,
            );

            if ($candidate === null) {
                throw new NoCapacityException(
                    "Brak węzła typu {$type->shortLabel()} z wolnymi zasobami na {$server->vcpu} vCPU, "
                    ."{$server->ram_mb} MB RAM i {$server->disk_gb} GB dysku."
                );
            }

            // Ponowny odczyt pod blokadą — stan sprzed sekundy mógł się zmienić.
            $hypervisor = Hypervisor::query()->lockForUpdate()->findOrFail($candidate->id);

            if (! $hypervisor->hasCapacityFor($server->vcpu, $server->ram_mb, $server->disk_gb)) {
                throw new NoCapacityException(
                    "Hypervisor {$hypervisor->name} nie ma już wolnych zasobów na tę maszynę."
                );
            }

            $hypervisor->increment('cpu_cores_used', $server->vcpu);
            $hypervisor->increment('ram_mb_used', $server->ram_mb);
            $hypervisor->increment('disk_gb_used', $server->disk_gb);

            $server->hypervisor()->associate($hypervisor)->save();

            return $hypervisor->refresh();
        });
    }

    /** Zwalnia zasoby — po usunięciu maszyny albo po nieudanym provisioningu. */
    public function release(Server $server): void
    {
        if ($server->hypervisor_id === null) {
            return;
        }

        DB::transaction(function () use ($server) {
            $hypervisor = Hypervisor::query()->lockForUpdate()->find($server->hypervisor_id);

            if ($hypervisor === null) {
                return;
            }

            // decrement() bez podłogi zszedłby poniżej zera przy podwójnym
            // zwolnieniu (np. ponowione zadanie) i zafałszował pojemność node'a.
            $hypervisor->forceFill([
                'cpu_cores_used' => max(0, $hypervisor->cpu_cores_used - $server->vcpu),
                'ram_mb_used' => max(0, $hypervisor->ram_mb_used - $server->ram_mb),
                'disk_gb_used' => max(0, $hypervisor->disk_gb_used - $server->disk_gb),
            ])->save();
        });
    }

    /**
     * Przelicza zajętość od zera na podstawie maszyn w bazie.
     * Używane po awarii albo ręcznych zmianach — księgowanie przyrostowe
     * potrafi się rozjechać, a to jest źródło prawdy.
     */
    public function recalculate(Hypervisor $hypervisor): void
    {
        $totals = Server::query()
            ->where('hypervisor_id', $hypervisor->id)
            ->selectRaw('COALESCE(SUM(vcpu), 0) as vcpu, COALESCE(SUM(ram_mb), 0) as ram_mb, COALESCE(SUM(disk_gb), 0) as disk_gb')
            ->first();

        $hypervisor->forceFill([
            'cpu_cores_used' => (int) $totals->vcpu,
            'ram_mb_used' => (int) $totals->ram_mb,
            'disk_gb_used' => (int) $totals->disk_gb,
        ])->save();
    }
}
