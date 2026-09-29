{{-- Konfiguracja app-console.js. Bez konsoli (przegląd) skrypt tylko odświeża stan i obsługuje zasilanie. --}}
<script>
    window.VH_APP = {
        status: @js(route('panel.apps.status', $app)),
        logs: @js(route('panel.apps.logs', $app)),
        power: @js(route('panel.apps.power', $app)),
        command: @js(route('panel.apps.command', $app)),
        session: @js(($withConsole ?? true) && \App\Domain\Console\ConsoleSessions::enabled() && ! $app->isSuspended() ? route('panel.apps.console', $app) : null),
        console: @js($withConsole ?? true),
        installing: @js($app->isInstalling()),
        labels: {
            running: @js(__('działa')), offline: @js(__('wyłączona')), starting: @js(__('uruchamianie')),
            installing: @js(__('instalacja')), unreachable: @js(__('węzeł nie odpowiada')), missing: @js(__('brak na węźle')),
            unknown: '—', install: @js(__('— log instalacji —')),
            live: @js(__('na żywo')), polling: @js(__('odświeżanie co 1,5 s')), connecting: @js(__('łączenie…')),
            lost: @js(__('Połączenie z konsolą przerwane — łączę ponownie…')), noCommand: @js(__('Aplikacja nie działa — uruchom ją, żeby wysłać polecenie.')),
        },
    };
</script>
