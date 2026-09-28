@extends('layouts.panel')

@section('title', $app->name)

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/xterm/xterm.css') }}">
@endpush

@section('content')
    @include('panel.apps._header')

    <div class="app-stats">
        <div class="card stat"><div class="stat-label">{{ __('Stan') }}</div><div class="stat-value" data-stat="state">—</div></div>
        <div class="card stat"><div class="stat-label">{{ __('Procesor') }}</div><div class="stat-value" data-stat="cpu">—</div>
            <div class="hint">{{ $app->cpu_percent ? __('limit :percent%', ['percent' => $app->cpu_percent]) : __('bez limitu') }}</div></div>
        <div class="card stat"><div class="stat-label">{{ __('Pamięć') }}</div><div class="stat-value" data-stat="memory">—</div>
            <div class="hint">{{ __('z :mb MB', ['mb' => $app->memory_mb]) }}</div></div>
        <div class="card stat"><div class="stat-label">{{ __('Dysk') }}</div><div class="stat-value" data-stat="disk">—</div>
            <div class="hint">{{ __('z :mb MB', ['mb' => $app->disk_mb]) }}</div></div>
    </div>

    <div class="app-console-frame">
        <div class="app-console-bar">
            <span class="pill neutral" id="app-live">{{ __('łączenie…') }}</span>
            <button class="btn btn-sm" type="button" id="app-console-full"><x-icon name="maximize" :size="14"/> {{ __('Pełny ekran') }}</button>
        </div>
        <div class="app-console" id="app-console"></div>
    </div>
    @if ($app->acceptsCommands())
        <form class="console-input" id="app-command">
            <input type="text" name="command" maxlength="2000" autocomplete="off" placeholder="{{ __('Wpisz polecenie i naciśnij Enter…') }}" aria-label="{{ __('Polecenie konsoli') }}">
            <button class="btn" type="submit">{{ __('Wyślij') }}</button>
        </form>
    @endif
@endsection

@push('scripts')
    <script>
        window.VH_APP = {
            status: @js(route('panel.apps.status', $app)),
            logs: @js(route('panel.apps.logs', $app)),
            power: @js(route('panel.apps.power', $app)),
            command: @js(route('panel.apps.command', $app)),
            session: @js(\App\Domain\Console\ConsoleSessions::enabled() && ! $app->isSuspended() ? route('panel.apps.console', $app) : null),
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
    <script src="{{ asset('vendor/xterm/xterm.js') }}"></script>
    <script src="{{ asset('vendor/xterm/addon-fit.js') }}"></script>
    <script src="{{ asset('js/app-console.js') }}?v={{ @filemtime(public_path('js/app-console.js')) }}"></script>
@endpush
