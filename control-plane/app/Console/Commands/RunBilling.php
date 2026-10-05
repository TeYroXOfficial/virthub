<?php

namespace App\Console\Commands;

use App\Domain\Billing\Billing;
use App\Domain\Billing\ServiceManager;
use Illuminate\Console\Command;

/** Zadania okresowe billingu (harmonogram co 5 minut). */
class RunBilling extends Command
{
    protected $signature = 'virthub:billing';

    protected $description = 'Opłaty godzinowe, faktury odnowień, przypomnienia, zawieszanie i usuwanie za brak płatności';

    public function handle(ServiceManager $services): int
    {
        if (! Billing::enabled()) {
            return self::SUCCESS;
        }
        foreach ($services->run() as $step => $count) {
            if ($count > 0) {
                $this->line("{$step}: {$count}");
            }
        }

        return self::SUCCESS;
    }
}
