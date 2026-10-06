<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdminCommand extends Command
{
    protected $signature = 'license:admin {email} {--name=Administrator}';

    protected $description = 'Tworzy konto administratora serwera licencji (albo nadaje nowe hasło)';

    public function handle(): int
    {
        $password = Str::password(20, symbols: false);
        User::query()->updateOrCreate(['email' => $this->argument('email')], ['name' => $this->option('name'), 'password' => $password, 'is_admin' => true]);
        $this->info('Konto administratora gotowe.');
        $this->line('E-mail: '.$this->argument('email'));
        $this->line('Hasło:  '.$password);

        return self::SUCCESS;
    }
}
