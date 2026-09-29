@extends('layouts.panel')

@section('title', $app->name)

@push('head')
    <link rel="stylesheet" href="{{ asset('vendor/xterm/xterm.css') }}">
@endpush

@section('content')
    @include('panel.apps._header')

    @if ($app->isInstalling())
        @include('panel.apps._progress')
    @endif

    @include('panel.apps._stats')

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
    @include('panel.apps._live-config')
    <script src="{{ asset('vendor/xterm/xterm.js') }}"></script>
    <script src="{{ asset('vendor/xterm/addon-fit.js') }}"></script>
    <script src="{{ asset('js/app-console.js') }}?v={{ @filemtime(public_path('js/app-console.js')) }}"></script>
@endpush
