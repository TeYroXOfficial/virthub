<?php

namespace App\Domain\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Poczta wychodząca ustawiana w Administracji. Zapisane wartości nadpisują
 * MAIL_* z .env; dopóki nikt nic nie zapisał, działa to, co jest w .env.
 */
class MailSettings
{
    public const ENCRYPTIONS = ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)'];

    /**
     * Aktualne ustawienia do formularza (bez hasła).
     *
     * @return array{enabled: bool, host: ?string, port: int, encryption: string, username: ?string, has_password: bool, from_address: ?string, from_name: ?string}
     */
    public static function current(): array
    {
        $smtp = config('mail.mailers.smtp');
        $port = (int) ($smtp['port'] ?? 587);

        return [
            'enabled' => config('mail.default') === 'smtp',
            'host' => $smtp['host'] ?? null,
            'port' => $port,
            'encryption' => ($smtp['scheme'] ?? null) === 'smtps' || $port === 465 ? 'ssl' : 'tls',
            'username' => $smtp['username'] ?? null,
            'has_password' => filled($smtp['password'] ?? null),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
        ];
    }

    /** Czy wiadomości naprawdę wychodzą (a nie lądują w logu). */
    public static function configured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /** Nadpisuje konfigurację poczty ustawieniami z bazy. Wołane przy starcie aplikacji. */
    public static function apply(): void
    {
        // config:cache zapisuje konfigurację do pliku — hasło z bazy nie może tam trafić.
        if (app()->runningConsoleCommand('config:cache', 'optimize')) {
            return;
        }
        try {
            $s = Setting::allValues();
        } catch (Throwable) {
            return; // brak tabeli (przed migracją) — zostaje .env
        }
        if (! isset($s['mail.mailer'])) {
            return;
        }

        $password = null;
        if (filled($s['mail.password'] ?? null)) {
            try {
                $password = Crypt::decryptString($s['mail.password']);
            } catch (Throwable) {
                $password = null; // zmieniony APP_KEY — hasło trzeba wpisać ponownie
            }
        }

        config([
            'mail.default' => $s['mail.mailer'],
            'mail.mailers.smtp.host' => $s['mail.host'] ?? null,
            'mail.mailers.smtp.port' => (int) ($s['mail.port'] ?? 587),
            'mail.mailers.smtp.scheme' => ($s['mail.encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.username' => $s['mail.username'] ?? null,
            'mail.mailers.smtp.password' => $password,
            'mail.from.address' => $s['mail.from_address'] ?? config('mail.from.address'),
            'mail.from.name' => $s['mail.from_name'] ?? config('mail.from.name'),
        ]);

        if (app()->resolved('mail.manager')) {
            Mail::purge('smtp'); // zapis w tym samym żądaniu (np. test zaraz po zapisaniu)
        }
    }

    /**
     * @param  array{enabled: bool, host?: ?string, port?: ?int, encryption?: ?string, username?: ?string, password?: ?string, from_address: string, from_name?: ?string}  $data
     */
    public static function save(array $data): void
    {
        $values = [
            'mail.mailer' => $data['enabled'] ? 'smtp' : 'log',
            'mail.host' => $data['host'] ?? null,
            'mail.port' => isset($data['port']) ? (string) $data['port'] : null,
            'mail.encryption' => $data['encryption'] ?? 'tls',
            'mail.username' => $data['username'] ?? null,
            'mail.from_address' => $data['from_address'],
            'mail.from_name' => $data['from_name'] ?? null,
        ];
        // Puste pole hasła = bez zmian (formularz nie pokazuje zapisanego hasła).
        if (filled($data['password'] ?? null)) {
            $values['mail.password'] = Crypt::encryptString($data['password']);
        } elseif (empty($data['username'])) {
            $values['mail.password'] = null;
        }

        Setting::put($values);
        self::apply();
    }
}
