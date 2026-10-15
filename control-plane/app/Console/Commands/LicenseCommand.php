<?php

namespace App\Console\Commands;

use App\Domain\Licensing\LicenseManager;
use Illuminate\Console\Command;

/**
 * Klucz licencji z wiersza poleceń (instalator panelu z portalu):
 *   php artisan virthub:license VH-XXXX-XXXX-XXXX-XXXX
 */
class LicenseCommand extends Command
{
    protected $signature = 'virthub:license {key? : klucz licencji; bez niego — stan} {--remove : usuń klucz}';

    protected $description = 'Ustawia klucz licencji i sprawdza go w portalu';

    public function handle(LicenseManager $license): int
    {
        if ($this->option('remove')) {
            $license->remove();
            $this->info('Klucz licencji usunięty.');

            return self::SUCCESS;
        }
        $key = $this->argument('key');
        if (! $license->configured()) {
            $this->error('Brak VIRTHUB_LICENSE_SERVER albo VIRTHUB_LICENSE_PUBLIC_KEY w .env.');

            return self::FAILURE;
        }
        $status = $key ? $license->activate((string) $key) : $license->refresh();
        $this->line('Stan licencji: '.$status['state'].($status['error'] ? ' — '.$status['error'] : ''));
        if ($status['state'] === LicenseManager::STATE_VALID) {
            $payload = $status['payload'];
            $this->info(sprintf('Licencja %s, węzłów: %s, kanał: %s.', $payload['type'] ?? 'paid', $payload['nodes'] ?? '∞', $payload['channel'] ?? 'stable'));
        }

        return $status['state'] === LicenseManager::STATE_VALID ? self::SUCCESS : self::FAILURE;
    }
}
