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
    /**
     * @param  (callable(Hypervisor): bool)|null  $accepts  dodatkowy warunek, np.
     *                                                      wolne adresy w pulach węzła
     */
    public function select(
        int $vcpu,
        int $ramMb,
        int $diskGb,
        Virtualization $virtualization = Virtualization::Kvm,
        ?callable $accepts = null,
    ): ?Hypervisor {
        return Hypervisor::query()
            ->available()
            // Kontener nie stanie na węźle KVM (brak Incusa) ani maszyna
            // wirtualna na węźle bez VT-x.
            ->where('virtualization', $virtualization->value)
            ->get()
            ->filter(fn (Hypervisor $h) => $h->hasCapacityFor($vcpu, $ramMb, $diskGb))
            ->filter(fn (Hypervisor $h) => $accepts === null || $accepts($h))
            ->sortByDesc(fn (Hypervisor $h) => $h->utilisationPercent())
            ->first();
    }

    /**
     * Rezerwuje zasoby pod maszynę. Zwraca hypervisor, na którym maszyna
     * faktycznie się zmieściła.
     *
     * @param  (callable(Hypervisor): bool)|null  $accepts  patrz select()
     *
     * @throws NoCapacityException|NoAddressesException
     */
    public function reserve(Server $server, ?Hypervisor $preferred = null, ?callable $accepts = null): Hypervisor
    {
        return DB::transaction(function () use ($server, $preferred, $accepts) {
            $type = $server->virtualization ?? Virtualization::Kvm;

            if ($preferred !== null && $preferred->virtualization !== $type) {
                throw new NoCapacityException(
                    "Węzeł {$preferred->name} uruchamia {$preferred->virtualization->label()}, "
                    ."a zamówienie dotyczy: {$type->label()}."
                );
            }

            $candidate = $preferred ?? $this->select(
                $server->vcpu, $server->ram_mb, $server->disk_gb, $type, $accepts,
            );

            // Zasoby są, ale żaden węzeł nie ma adresów — komunikat musi
            // wskazać pule, a nie pojemność, bo to tam administrator ma szukać.
            if ($candidate === null && $accepts !== null
                && $this->select($server->vcpu, $server->ram_mb, $server->disk_gb, $type) !== null) {
                throw new NoAddressesException(
                    "Pula adresów jest wyczerpana: węzły typu {$type->shortLabel()} z wolnymi zasobami "
                    .'nie mają adresów wymaganych przez pakiet. Dodaj pulę dla węzła albo jego grupy.'
                );
            }

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
