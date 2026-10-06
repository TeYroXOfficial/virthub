<div class="page-header">
    <div>
        <h1>{{ __('Aplikacje') }}</h1>
        <p class="lede">{{ __('Serwery gier i boty klientów w kontenerach Dockera na węzłach. Szablony to eggi w formacie Pterodactyla.') }}</p>
    </div>
</div>
<nav class="tabs" aria-label="{{ __('Sekcje aplikacji') }}">
    <a href="{{ route('panel.admin.apps') }}" @if (request()->routeIs('panel.admin.apps')) aria-current="page" @endif>{{ __('Wszystkie aplikacje') }}</a>
    <a href="{{ route('panel.admin.apps.eggs') }}" @if (request()->routeIs('panel.admin.apps.eggs')) aria-current="page" @endif>{{ __('Szablony (eggi)') }}</a>
    <a href="{{ route('panel.admin.apps.plans') }}" @if (request()->routeIs('panel.admin.apps.plans')) aria-current="page" @endif>{{ __('Plany') }}</a>
    <a href="{{ route('panel.admin.apps.databases') }}" @if (request()->routeIs('panel.admin.apps.databases')) aria-current="page" @endif>{{ __('Bazy danych') }}</a>
    <a href="{{ route('panel.admin.apps.pterodactyl') }}" @if (request()->routeIs('panel.admin.apps.pterodactyl')) aria-current="page" @endif>{{ __('Migracja z Pterodactyla') }}</a>
</nav>
