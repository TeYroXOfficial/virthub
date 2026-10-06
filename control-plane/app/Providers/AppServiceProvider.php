<?php

namespace App\Providers;

use App\Domain\Apps\Databases\DatabaseServer;
use App\Domain\Apps\Databases\MysqlDatabaseServer;
use App\Domain\Settings\MailSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DatabaseServer::class, MysqlDatabaseServer::class);
        //
    }

    public function boot(): void
    {
        // Domyślne widoki paginacji zakładają Tailwind, którego panel nie używa
        // (celowo — cały arkusz stylów jest wbudowany w layout, bez kroku
        // budowania assetów). Prosty wariant renderuje czyste listy, które
        // stylujemy razem z resztą interfejsu.
        Paginator::defaultView('pagination::simple-default');
        Paginator::defaultSimpleView('pagination::simple-default');

        // Poczta ustawiona w Administracji nadpisuje MAIL_* z .env.
        MailSettings::apply();

        // E-mail z linkiem do nowego hasła w języku panelu (domyślny jest po angielsku).
        ResetPassword::toMailUsing(function ($user, string $token) {
            $url = route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);

            return (new MailMessage)
                ->subject(__('Ustawienie nowego hasła — :brand', ['brand' => config('virthub.brand')]))
                ->greeting(__('Cześć!'))
                ->line(__('Ktoś (miejmy nadzieję, że Ty) poprosił o ustawienie nowego hasła do panelu :brand.', ['brand' => config('virthub.brand')]))
                ->action(__('Ustaw nowe hasło'), $url)
                ->line(__('Link jest ważny :minutes minut. Jeśli to nie Ty, zignoruj tę wiadomość — hasło się nie zmieni.', ['minutes' => config('auth.passwords.users.expire', 60)]))
                ->salutation(config('virthub.brand'));
        });
    }
}
