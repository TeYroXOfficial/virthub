<?php

namespace App\Domain\Provisioning;

use App\Enums\Virtualization;
use App\Models\Hypervisor;
use App\Models\Server;
use App\Models\ServerDisk;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dobór hypervisora pod nową maszynę i księgowanie zajętych zasobów.
 *
 * Strategie (domyślna panelu albo własna grupy węzłów):
 * - pack — bin-packing: najbardziej zapełniony węzeł, na którym maszyna się
 *   mieści (duże maszyny zawsze mają gdzie stanąć);
 * - spread — najluźniejszy węzeł (mniejszy „hałas sąsiadów”, równe obciążenie);
 * - fewest — węzeł z najmniejszą liczbą maszyn;
 * - random — losowo wśród pasujących.
 *
 * Rezerwacja idzie w transakcji z blokadą wiersza hypervisora, bo dwa
 * równoległe zamówienia potrafią zobaczyć to samo wolne miejsce i oba je zająć.
 */
class HypervisorSelector
{
    public const STRATEGIES = ['pack', 'spread', 'fewest', 'random'];

    public static function defaultStrategy(): string
    {
        $value = (string) Setting::get('placement.strategy', config('virthub.placement_strategy', 'pack'));

        return in_array($value, self::STRATEGIES, true) ? $value : 'pack';
    }

    public static function label(string $strategy): string
    {
        return match ($strategy) {
            'spread' => __('rozpraszanie — najluźniejszy węzeł'),
            'fewest' => __('najmniej maszyn'),
            'random' => __('losowo'),
            default => __('upakowanie — najpełniejszy węzeł, na którym się zmieści'),
        };
    }

    /** Strategia dla zbioru kandydatów: grupy, gdy wszyscy są w jednej grupie z własną strategią. */
    public static function strategyFor(Collection $candidates): string
    {
        $groups = $candidates->map(fn (Hypervisor $h) => $h->group?->placement_strategy)->unique();

        return $groups->count() === 1 && in_array($groups->first(), self::STRATEGIES, true) ? $groups->first() : self::defaultStrategy();
    }

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
        $candidates = Hypervisor::query()
            ->with('group')
            ->withCount('servers')
            ->available()
            // Kontener nie stanie na węźle KVM (brak Incusa) ani maszyna
            // wirtualna na węźle bez VT-x.
            ->where('virtualization', $virtualization->value)
            ->get()
            ->filter(fn (Hypervisor $h) => $h->hasCapacityFor($vcpu, $ramMb, $diskGb))
            ->filter(fn (Hypervisor $h) => $h->acceptsMoreServers())
            ->filter(fn (Hypervisor $h) => $accepts === null || $accepts($h))
            ->values();
        if ($candidates->isEmpty()) {
            return null;
        }

        return match (self::strategyFor($candidates)) {
            'spread' => $candidates->sortBy(fn (Hypervisor $h) => [$h->utilisationPercent(), $h->id])->first(),
            'fewest' => $candidates->sortBy(fn (Hypervisor $h) => [$h->servers_count, $h->utilisationPercent(), $h->id])->first(),
            'random' => $candidates->random(),
            default => $candidates->sortByDesc(fn (Hypervisor $h) => $h->utilisationPercent())->first(),
        };
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
                    __('Węzeł :name uruchamia :label, ', ['name' => $preferred->name, 'label' => $preferred->virtualization->label()])
                    .__('a zamówienie dotyczy: :label.', ['label' => $type->label()])
                );
            }

            // Dysk główny i dodatkowe (z pakietu) — razem zajmują miejsce na węźle.
            $diskGb = $server->totalDiskGb();
            $candidate = $preferred ?? $this->select(
                $server->vcpu, $server->ram_mb, $diskGb, $type, $accepts,
            );

            // Zasoby są, ale żaden węzeł nie ma adresów — komunikat musi
            // wskazać pule, a nie pojemność, bo to tam administrator ma szukać.
            if ($candidate === null && $accepts !== null
                && $this->select($server->vcpu, $server->ram_mb, $diskGb, $type) !== null) {
                throw new NoAddressesException(
                    __('Pula adresów jest wyczerpana: węzły typu :shortlabel z wolnymi zasobami ', ['shortlabel' => $type->shortLabel()])
                    .__('nie mają adresów wymaganych przez pakiet. Dodaj pulę dla węzła albo jego grupy.')
                );
            }

            if ($candidate === null) {
                throw new NoCapacityException(
                    __('Brak węzła typu :shortlabel z wolnymi zasobami na :vcpu vCPU, ', ['shortlabel' => $type->shortLabel(), 'vcpu' => $server->vcpu])
                    ."{$server->ram_mb} MB RAM i {$diskGb} GB dysku."
                );
            }

            // Ponowny odczyt pod blokadą — stan sprzed sekundy mógł się zmienić.
            $hypervisor = Hypervisor::query()->lockForUpdate()->findOrFail($candidate->id);

            if (! $hypervisor->hasCapacityFor($server->vcpu, $server->ram_mb, $diskGb)) {
                throw new NoCapacityException(
                    __('Hypervisor :name nie ma już wolnych zasobów na tę maszynę.', ['name' => $hypervisor->name])
                );
            }

            $hypervisor->increment('cpu_cores_used', $server->vcpu);
            $hypervisor->increment('ram_mb_used', $server->ram_mb);
            $hypervisor->increment('disk_gb_used', $diskGb);

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

        $diskGb = $server->totalDiskGb();
        DB::transaction(function () use ($server, $diskGb) {
            $hypervisor = Hypervisor::query()->lockForUpdate()->find($server->hypervisor_id);

            if ($hypervisor === null) {
                return;
            }

            // decrement() bez podłogi zszedłby poniżej zera przy podwójnym
            // zwolnieniu (np. ponowione zadanie) i zafałszował pojemność node'a.
            $hypervisor->forceFill([
                'cpu_cores_used' => max(0, $hypervisor->cpu_cores_used - $server->vcpu),
                'ram_mb_used' => max(0, $hypervisor->ram_mb_used - $server->ram_mb),
                'disk_gb_used' => max(0, $hypervisor->disk_gb_used - $diskGb),
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

        $extra = ServerDisk::query()->whereIn('server_id', Server::query()->where('hypervisor_id', $hypervisor->id)->select('id'))->sum('size_gb');

        $hypervisor->forceFill([
            'cpu_cores_used' => (int) $totals->vcpu,
            'ram_mb_used' => (int) $totals->ram_mb,
            'disk_gb_used' => (int) $totals->disk_gb + (int) $extra,
        ])->save();
    }
}
