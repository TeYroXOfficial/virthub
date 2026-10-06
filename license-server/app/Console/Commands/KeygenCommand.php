<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class KeygenCommand extends Command
{
    protected $signature = 'license:keygen {--force : nadpisz istniejący klucz (unieważnia wszystkie tokeny i paczki!)}';

    protected $description = 'Generuje parę kluczy Ed25519 do podpisywania licencji i addonów';

    public function handle(): int
    {
        if (config('licensing.signing_key') && ! $this->option('force')) {
            $this->error('LICENSE_SIGNING_KEY jest już ustawiony. Nowy klucz unieważni podpisy u wszystkich paneli — użyj --force, jeśli na pewno.');

            return self::FAILURE;
        }
        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));

        $env = base_path('.env');
        if (is_writable($env)) {
            $content = (string) file_get_contents($env);
            $content = preg_match('/^LICENSE_SIGNING_KEY=.*$/m', $content)
                ? preg_replace('/^LICENSE_SIGNING_KEY=.*$/m', 'LICENSE_SIGNING_KEY='.$secret, $content)
                : rtrim($content)."\nLICENSE_SIGNING_KEY={$secret}\n";
            file_put_contents($env, $content);
            $this->info('Klucz prywatny zapisany w .env (LICENSE_SIGNING_KEY). Zrób jego kopię zapasową — bez niego nie wystawisz nowych tokenów.');
        } else {
            $this->line("LICENSE_SIGNING_KEY={$secret}");
        }
        $this->newLine();
        $this->info('Klucz publiczny — wpisz go w .env każdego panelu VirtHub:');
        $this->line("VIRTHUB_LICENSE_PUBLIC_KEY={$public}");

        return self::SUCCESS;
    }
}
