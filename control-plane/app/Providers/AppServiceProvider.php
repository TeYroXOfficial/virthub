<?php

namespace App\Providers;

use App\Domain\Apps\Databases\DatabaseServer;
use App\Domain\Apps\Databases\MysqlDatabaseServer;
use App\Domain\External\ProviderRegistry;
use App\Domain\Licensing\AddonManager;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Settings\Languages;
use App\Domain\Settings\MailSettings;
use App\Support\TranslationLoader;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\TranslationServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DatabaseServer::class, MysqlDatabaseServer::class);
        $this->app->singleton(LicenseManager::class);
        $this->app->singleton(AddonManager::class);
        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(Languages::class);

        // Tłumaczenia z panelu (storage/app/lang) nakładane na pliki z lang/. Dostawca
        // tłumaczeń Laravela jest odroczony — rejestrujemy go teraz, inaczej przy
        // pierwszym użyciu nadpisałby nasz loader swoim.
        $this->app->register(TranslationServiceProvider::class);
        $this->app->extend('translation.loader', fn ($loader, $app) => new TranslationLoader(
            $app['files'],
            $loader instanceof FileLoader ? $loader->paths() : [$app->langPath()],
            storage_path('app/lang'),
        ));
    }

    public function boot(): void
    {
        // Domyślne widoki paginacji zakładają Tailwind, którego panel nie używa
        // (celowo — cały arkusz stylów jest wbudowany w layout, bez kroku
        // budowania assetów). Prosty wariant renderuje czyste listy, które
        // stylujemy razem z resztą interfejsu.
        Paginator::defaultView('pagination::simple-default');

        // Języki z Administracji (domyślny, włączone, dodane) zamiast z config.
        try {
            $this->app->make(Languages::class)->apply();
        } catch (QueryException) {
            // przed migracjami — zostają języki z config/virthub.php
        }

        // Addony objęte licencją (ich ServiceProvidery rejestrują np. sterowniki
        // dostawców). Brak tabel (świeża instalacja, migracje) nie blokuje startu.
        try {
            $this->app->make(AddonManager::class)->boot();
        } catch (QueryException) {
            // brak tabeli ustawień — przed pierwszą migracją
        } catch (\Throwable $e) {
            report($e);
        }
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
