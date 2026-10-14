<?php

namespace App\Domain\Storage;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Models\Datastore;
use App\Models\Hypervisor;
use Illuminate\Support\Collection;

/**
 * Odczyt pojemności magazynów danych z węzłów. Każdy węzeł raportuje magazyny,
 * które ma przypięte — katalog widzi tylko on, Ceph wszyscy (ten sam klaster).
 */
class Datastores
{
    /** Odświeża jeden magazyn na wszystkich jego węzłach. Zwraca liczbę udanych odczytów. */
    public function refresh(Datastore $datastore): int
    {
        $ok = 0;
        foreach ($datastore->hypervisors as $node) {
            $ok += $this->refreshNode($node, collect([$datastore]));
        }

        return $ok;
    }

    /** Odświeża wszystkie aktywne magazyny (harmonogram). */
    public function refreshAll(): int
    {
        $ok = 0;
        Hypervisor::query()->with(['datastores' => fn ($q) => $q->where('is_active', true)])->get()
            ->filter(fn (Hypervisor $node) => $node->datastores->isNotEmpty())
            ->each(function (Hypervisor $node) use (&$ok) {
                $ok += $this->refreshNode($node, $node->datastores);
            });

        return $ok;
    }

    /** @param  Collection<int, Datastore>  $datastores */
    private function refreshNode(Hypervisor $node, $datastores): int
    {
        $now = now();
        try {
            $reports = collect((new AgentClient($node))->datastoresStatus($datastores->map->toAgentPayload()->values()->all()))->keyBy('id');
        } catch (AgentException $e) {
            foreach ($datastores as $datastore) {
                $node->datastores()->updateExistingPivot($datastore->id, ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500), 'checked_at' => $now]);
            }

            return 0;
        }

        $ok = 0;
        foreach ($datastores as $datastore) {
            $report = (array) ($reports[$datastore->id] ?? ['ok' => false, 'error' => __('Węzeł nie zwrócił raportu magazynu.')]);
            $good = (bool) ($report['ok'] ?? false);
            $ok += (int) $good;
            $node->datastores()->updateExistingPivot($datastore->id, [
                'ok' => $good,
                'total_gb' => $good ? (int) ($report['total_gb'] ?? 0) : null,
                'free_gb' => $good ? (int) ($report['free_gb'] ?? 0) : null,
                'error' => $good ? null : mb_substr((string) ($report['error'] ?? ''), 0, 500),
                'checked_at' => $now,
            ]);
        }

        return $ok;
    }
}
