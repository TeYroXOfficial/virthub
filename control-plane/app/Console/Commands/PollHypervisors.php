<?php

namespace App\Console\Commands;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Provisioning\TemplateDistributor;
use App\Models\Hypervisor;
use Illuminate\Console\Command;

/**
 * Heartbeat floty. Node bez świeżego heartbeatu wypada z doboru pod nowe
 * maszyny — lepiej odmówić zamówienia niż wysłać je na hypervisor, który nie
 * odpowiada.
 */
class PollHypervisors extends Command
{
    protected $signature = 'virthub:poll-hypervisors';

    protected $description = 'Odpytuje agentów o stan hypervisorów i aktualizuje pojemność floty';

    public function handle(): int
    {
        $hypervisors = Hypervisor::all();

        if ($hypervisors->isEmpty()) {
            $this->comment('Brak zarejestrowanych hypervisorów.');

            return self::SUCCESS;
        }

        foreach ($hypervisors as $hypervisor) {
            $this->poll($hypervisor);
        }

        return self::SUCCESS;
    }

    private function poll(Hypervisor $hypervisor): void
    {
        try {
            $health = (new AgentClient($hypervisor))->health();
        } catch (AgentException $e) {
            // Tryb konserwacji ustawia administrator ręcznie — nie nadpisujemy go
            // statusem „offline", bo to zatarłoby jego decyzję.
            if ($hypervisor->status !== Hypervisor::STATUS_MAINTENANCE) {
                $hypervisor->forceFill(['status' => Hypervisor::STATUS_OFFLINE])->save();
            }

            $this->error("{$hypervisor->name}: {$e->getMessage()}");

            return;
        }

        $hypervisor->forceFill([
            'status' => $hypervisor->status === Hypervisor::STATUS_MAINTENANCE
                ? Hypervisor::STATUS_MAINTENANCE
                : Hypervisor::STATUS_ONLINE,
            'last_seen_at' => now(),
            'last_health' => $health,
        ])->save();

        $this->info(sprintf(
            '%s: %s, %d VM, wolne %d MB RAM / %d GB dysku',
            $hypervisor->name,
            $health['driver'] ?? '?',
            $health['running_vms'] ?? 0,
            $health['ram_mb_free'] ?? 0,
            $health['disk_gb_free'] ?? 0,
        ));

        // Węzeł kontenerów, który właśnie dołączył albo wrócił po awarii,
        // dociąga szablony dodane w międzyczasie. Szablony już pobrane albo w
        // trakcie pobierania są pomijane, więc przy zwykłym heartbeacie to nic
        // nie kosztuje.
        $queued = app(TemplateDistributor::class)->syncNode($hypervisor);
        if ($queued > 0) {
            $this->line("  zlecono pobranie {$queued} szablonów kontenerów");
        }
    }
}
