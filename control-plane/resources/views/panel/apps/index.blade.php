@extends('layouts.panel')

@section('title', __('Aplikacje'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Aplikacje') }}</h1>
            <p class="lede">{{ __('Serwery gier, boty Discord i inne usługi w kontenerach — konsola, pliki i zasilanie w przeglądarce.') }}</p>
        </div>
        @can('create', \App\Models\AppServer::class)
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('panel.apps.create') }}"><x-icon name="plus" :size="16"/> {{ __('Nowa aplikacja') }}</a>
            </div>
        @endcan
    </div>

    @if ($apps->isEmpty())
        <div class="card empty">
            <x-icon name="gamepad" :size="40"/>
            <p>{{ __('Nie masz jeszcze żadnej aplikacji.') }}</p>
            @can('create', \App\Models\AppServer::class)
                <a class="btn btn-primary" href="{{ route('panel.apps.create') }}">{{ __('Utwórz pierwszą aplikację') }}</a>
            @endcan
        </div>
    @else
        <div class="card" style="padding:0">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>{{ __('Nazwa') }}</th><th>{{ __('Szablon') }}</th><th>{{ __('Adres') }}</th><th>{{ __('Zasoby') }}</th><th>{{ __('Stan') }}</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($apps as $app)
                        <tr>
                            <td><a href="{{ route('panel.apps.show', $app) }}"><strong>{{ $app->name }}</strong></a></td>
                            <td class="muted">{{ $app->egg?->name }}</td>
                            <td class="mono">{{ $app->address() ?? '—' }}</td>
                            <td class="num">{{ __(':memory MB RAM · :disk MB', ['memory' => $app->memory_mb, 'disk' => $app->disk_mb]) }}</td>
                            <td><span class="pill {{ $app->isSuspended() || $app->status === 'install_failed' ? 'critical' : ($app->isInstalling() ? 'warning' : 'neutral') }}">{{ $app->statusLabel() }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
