<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Zakładanie konta administratora z linii poleceń.
 *
 * Istnieje po to, żeby instalator nie musiał przepychać kodu PHP przez
 * `tinker --execute` — cytowanie w powłoce jest tam polem minowym, a błąd
 * kończy się kontem z hasłem, którego nikt nie zna.
 */
class CreateAdmin extends Command
{
    protected $signature = 'virthub:create-admin
                            {--email= : Adres e-mail konta}
                            {--password= : Hasło; pominięte oznacza wygenerowanie losowego}
                            {--name=Administrator : Nazwa wyświetlana}
                            {--if-none : Nie rób nic, jeśli jakikolwiek administrator już istnieje}
                            {--porcelain : Wypisz wyłącznie hasło, bez innych komunikatów (dla skryptów)}';

    protected $description = 'Zakłada konto administratora panelu';

    public function handle(): int
    {
        $porcelain = (bool) $this->option('porcelain');

        // Instalator wywołuje to przy każdym uruchomieniu. Nadpisanie hasła
        // działającemu administratorowi byłoby niespodzianką, a nie pomocą.
        if ($this->option('if-none') && User::where('role', User::ROLE_ADMIN)->exists()) {
            $porcelain || $this->info('Administrator już istnieje — nic nie zmieniam.');

            return self::SUCCESS;
        }

        $email = $this->option('email') ?: $this->ask('Adres e-mail administratora');
        $password = $this->option('password') ?: $this->generatePassword();

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            [
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
            ],
            ['password.min' => 'Hasło administratora musi mieć co najmniej 12 znaków.'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            // Ponowne uruchomienie instalatora nie może wywalić się na tym, że
            // konto już jest — podnosimy uprawnienia i ustawiamy nowe hasło.
            $existing->forceFill([
                'password' => $password,
                'role' => User::ROLE_ADMIN,
                'suspended_at' => null,
            ])->save();

            $porcelain || $this->info("Konto {$email} istniało — nadano rolę administratora i ustawiono nowe hasło.");
        } else {
            // forceFill, bo email_verified_at celowo nie jest w $fillable —
            // przez create() zostałoby po cichu pominięte.
            (new User)->forceFill([
                'name' => $this->option('name'),
                'email' => $email,
                'password' => $password,
                'role' => User::ROLE_ADMIN,
                'email_verified_at' => now(),
            ])->save();

            $porcelain || $this->info("Utworzono konto administratora: {$email}");
        }

        AuditLog::record('user.admin_created', null, ['email' => $email]);

        if ($porcelain) {
            // Samo hasło, bez ozdobników — instalator podstawia to do zmiennej.
            $this->output->writeln($password);
        } elseif (! $this->option('password')) {
            $this->newLine();
            $this->line('Hasło: '.$password);
            $this->warn('Zapisz je teraz — nie zostanie wyświetlone ponownie.');
        }

        return self::SUCCESS;
    }

    /**
     * Bez znaków, które przy przepisywaniu z terminala prowadzą do pomyłek
     * (0/O, 1/l/I) i bez takich, które powłoka próbowałaby interpretować.
     */
    private function generatePassword(int $length = 20): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
