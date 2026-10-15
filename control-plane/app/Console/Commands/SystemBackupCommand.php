<?php

namespace App\Console\Commands;

use App\Domain\System\SystemBackup;
use Illuminate\Console\Command;

class SystemBackupCommand extends Command
{
    protected $signature = 'virthub:system-backup {--if-scheduled : tylko gdy w panelu włączono kopię dobową}';

    protected $description = 'Kopia systemowa panelu: baza, .env i storage/app w storage/app/system-backups';

    public function handle(SystemBackup $backups): int
    {
        if ($this->option('if-scheduled') && ! SystemBackup::dailyEnabled()) {
            return self::SUCCESS;
        }
        $name = $backups->create();
        $this->info("Utworzono {$name} w ".SystemBackup::directory());

        return self::SUCCESS;
    }
}
