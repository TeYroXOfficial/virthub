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
        @elseif (\App\Domain\Billing\Billing::enabled())
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('panel.store') }}"><x-icon name="plus" :size="16"/> {{ __('Nowa aplikacja') }}</a>
            </div>
        @endcan
    </div>

    @if ($apps->isNotEmpty())
        <div class="grid grid-4" style="margin-bottom:20px">
            <div class="stat">
                <div class="stat-label">{{ __('Wszystkie') }}</div>
                <div class="stat-value">{{ $apps->count() }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">{{ __('Gotowe') }}</div>
                <div class="stat-value" style="color:var(--ok)">{{ $ready }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">{{ __('W trakcie operacji') }}</div>
                <div class="stat-value" style="color:var(--warn)">{{ $busy }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">{{ __('Łącznie RAM / dysk') }}</div>
                <div class="stat-value">{{ round($apps->sum('memory_mb') / 1024, 1) }} <span class="stat-sub">{{ __('GB') }}</span>
                    · {{ round($apps->sum('disk_mb') / 1024, 1) }} <span class="stat-sub">{{ __('GB') }}</span></div>
            </div>
        </div>
    @endif

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
                    <tr>
                        <th>{{ __('Nazwa') }}</th>
                        <th>{{ __('Stan') }}</th>
                        <th>{{ __('Adres') }}</th>
                        <th>{{ __('Szablon') }}</th>
                        <th>{{ __('Zasoby') }}</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($apps as $app)
                        <tr>
                            <td>
                                <a href="{{ route('panel.apps.show', $app) }}" style="font-weight:600">{{ $app->name }}</a>
                                @if (! empty($app->minecraft['mc']))
                                    <div class="hint">{{ trim(ucfirst($app->minecraft['platform'] ?? '').' '.$app->minecraft['mc']) }}@if (! empty($app->minecraft['modpack']['name'])) · {{ $app->minecraft['modpack']['name'] }}@endif</div>
                                @endif
                            </td>
                            <td><span class="pill {{ $app->statusTone() }}">{{ $app->statusLabel() }}</span></td>
                            <td class="mono">{{ $app->address() ?? '—' }}</td>
                            <td>
                                <span class="os-inline">
                                    <span class="os-badge app-badge"><x-icon :name="$app->icon()" :size="14"/></span>
                                    {{ $app->egg?->displayName() ?? '—' }}
                                </span>
                            </td>
                            <td class="num" style="white-space:nowrap">{{ __(':ram GB RAM · :disk GB', ['ram' => round($app->memory_mb / 1024, 1), 'disk' => round($app->disk_mb / 1024, 1)]) }}</td>
                            <td style="text-align:right"><a class="btn btn-sm" href="{{ route('panel.apps.show', $app) }}">{{ __('Zarządzaj') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
