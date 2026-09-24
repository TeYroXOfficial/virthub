<?php

namespace App\Domain\Provisioning;

use App\Enums\Virtualization;
use App\Jobs\PrefetchTemplateJob;
use App\Models\Hypervisor;
use App\Models\OsTemplate;
use App\Models\TemplateDownload;

/**
 * Rozsyłanie szablonów kontenerów na węzły.
 *
 * Obraz kontenera pobiera się raz na węzeł i trwa to minuty. Robimy to z
 * wyprzedzeniem — gdy administrator doda szablon albo gdy węzeł dołączy do
 * floty — żeby pierwszy klient nie czekał. Gdyby pobranie się nie odbyło,
 * węzeł i tak ściągnie obraz sam przy pierwszym zamówieniu; to optymalizacja,
 * nie warunek działania.
 */
class TemplateDistributor
{
    /**
     * Zlecenie w toku dłużej niż tyle minut uznajemy za zgubione (padł worker,
     * restart panelu) i zlecamy od nowa.
     */
    private const STALE_MINUTES = 120;

    /** Rozsyła szablon na wszystkie węzły kontenerów. Zwraca liczbę zleceń. */
    public function distribute(OsTemplate $template): int
    {
        if (! $template->isContainer() || ! $template->is_active) {
            return 0;
        }

        return $this->containerNodes()
            ->sum(fn (Hypervisor $node) => $this->queue($template, $node) ? 1 : 0);
    }

    /**
     * Uzupełnia na węźle brakujące szablony. Wołane przy każdym heartbeacie —
     * jest tanie, bo pomija szablony gotowe i te w trakcie pobierania.
     */
    public function syncNode(Hypervisor $node): int
    {
        if (! $node->runsContainers()) {
            return 0;
        }

        return OsTemplate::query()
            ->active()
            ->where('virtualization', Virtualization::Lxc->value)
            ->get()
            ->sum(fn (OsTemplate $template) => $this->queue($template, $node) ? 1 : 0);
    }

    /** Ponawia nieudane pobrania szablonu — na żądanie administratora. */
    public function retryFailed(OsTemplate $template): int
    {
        return $this->containerNodes()
            ->sum(fn (Hypervisor $node) => $this->queue($template, $node, retryFailed: true) ? 1 : 0);
    }

    private function queue(OsTemplate $template, Hypervisor $node, bool $retryFailed = false): bool
    {
        $download = TemplateDownload::firstOrNew([
            'os_template_id' => $template->id,
            'hypervisor_id' => $node->id,
        ]);

        if ($download->exists) {
            if ($download->status === TemplateDownload::STATUS_READY) {
                return false;
            }

            $stale = $download->updated_at?->lt(now()->subMinutes(self::STALE_MINUTES));

            if ($download->isInProgress() && ! $stale) {
                return false;
            }

            // Błąd nie jest ponawiany automatycznie: zły alias albo brak
            // dostępu do serwera obrazów nie naprawi się sam, a ponawianie co
            // minutę zaśmieciłoby kolejkę i logi węzła.
            if ($download->status === TemplateDownload::STATUS_FAILED && ! $retryFailed) {
                return false;
            }
        }

        $download->forceFill([
            'status' => TemplateDownload::STATUS_QUEUED,
            'agent_job_id' => null,
            'error' => null,
            'finished_at' => null,
        ])->save();

        PrefetchTemplateJob::dispatch($download->id);

        return true;
    }

    /** @return \Illuminate\Support\Collection<int, Hypervisor> */
    private function containerNodes()
    {
        return Hypervisor::query()
            ->where('virtualization', Virtualization::Lxc->value)
            ->whereNotNull('enrolled_at')
            ->get();
    }
}
