<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
    }
}
