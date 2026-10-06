@extends('layouts.panel')

@section('title', __('Sieć — :name', ['name' => $app->name]))

@php
    $host = $app->hypervisor?->publicAddress() ?? '?';
    $count = $app->allocations->count();
    $staff = auth()->user()->can('manage', $app);
@endphp

@section('content')
    @include('panel.apps._header')
    @error('port') <div class="alert alert-error">{{ $message }}</div> @enderror

    <div class="card flush dash-section">
        <div class="dash-head">
            <h3 class="card-title" style="margin:0"><x-icon name="network" :size="16"/> {{ __('Porty') }}
                <span class="pill neutral plain">{{ __(':count z :limit', ['count' => $count, 'limit' => $limit]) }}</span></h3>
            @can('operate', $app)
                <form method="POST" action="{{ route('panel.apps.ports.store', $app) }}" class="btn-row" style="margin:0">
                    @csrf
                    @if ($staff)
                        <input type="number" name="port" min="1" max="65535" placeholder="{{ __('dowolny wolny') }}" aria-label="{{ __('Numer portu') }}" style="max-width:150px">
                    @endif
                    <button class="btn btn-sm btn-primary" type="submit" @disabled(! $staff && $count >= $limit)><x-icon name="plus" :size="14"/> {{ __('Dodaj port') }}</button>
                </form>
            @endcan
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Adres') }}</th><th>{{ __('Port') }}</th><th>{{ __('Notatka') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($app->allocations as $alloc)
                    <tr>
                        <td><span class="mono copyable" data-copy="{{ $host }}:{{ $alloc->port }}" title="{{ __('Kliknij, żeby skopiować') }}">{{ $host }}:{{ $alloc->port }}</span>
                            @if ($alloc->is_primary) <span class="pill ok plain">{{ __('główny') }}</span> @endif</td>
                        <td class="num mono">{{ $alloc->port }} <span class="hint">TCP + UDP</span></td>
                        <td>
                            @can('operate', $app)
                                <form method="POST" action="{{ route('panel.apps.ports.note', [$app, $alloc]) }}" class="btn-row" style="margin:0; flex-wrap:nowrap">
                                    @csrf @method('PUT')
                                    <input name="notes" maxlength="60" value="{{ $alloc->notes }}" placeholder="{{ __('np. query, RCON, voice') }}" aria-label="{{ __('Notatka') }}" style="min-width:140px">
                                    <button class="btn btn-sm btn-ghost" type="submit">{{ __('Zapisz') }}</button>
                                </form>
                            @else
                                {{ $alloc->notes ?? '—' }}
                            @endcan
                        </td>
                        <td style="text-align:right; white-space:nowrap">
                            @can('operate', $app)
                                @unless ($alloc->is_primary)
                                    <form method="POST" action="{{ route('panel.apps.ports.primary', [$app, $alloc]) }}" style="display:inline; margin:0"
                                          data-confirm="{{ __('Ustawić :port jako port główny? Aplikacja dostanie go jako SERVER_PORT po restarcie.', ['port' => $alloc->port]) }}">
                                        @csrf
                                        <button class="btn btn-sm" type="submit">{{ __('Ustaw jako główny') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('panel.apps.ports.destroy', [$app, $alloc]) }}" style="display:inline; margin:0"
                                          data-confirm="{{ __('Usunąć port :port?', ['port' => $alloc->port]) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Usuń') }}"><x-icon name="trash" :size="14"/></button>
                                    </form>
                                @endunless
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">{{ __('Aplikacja nie ma portów.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-2" style="margin-top:16px">
        <div class="card">
            <h3 class="card-title">{{ __('Jak to działa') }}</h3>
            <ul class="plain-list">
                <li>{{ __('Port główny trafia do aplikacji jako SERVER_PORT i do jej adresu. Pozostałe porty możesz ustawić w konfiguracji serwera (np. query, RCON, mapa).') }}</li>
                <li>{{ __('Każdy port działa w TCP i UDP.') }}</li>
                <li>{{ __('Nowe porty kontener dostaje po restarcie aplikacji.') }}</li>
            </ul>
        </div>
        @if ($staff)
            <div class="card">
                <h3 class="card-title">{{ __('Limit portów') }} <span class="pill neutral">{{ __('personel') }}</span></h3>
                <form method="POST" action="{{ route('panel.apps.ports.limit', $app) }}" class="filter-bar">
                    @csrf @method('PUT')
                    <div class="field" style="margin:0">
                        <label for="pl">{{ __('Limit dla tej aplikacji') }}</label>
                        <input id="pl" type="number" name="port_limit" min="1" max="100" value="{{ $app->port_limit }}" placeholder="{{ __('z planu: :n', ['n' => $app->plan?->ports ?? '—']) }}" style="max-width:150px">
                    </div>
                    <button class="btn" type="submit">{{ __('Zapisz') }}</button>
                </form>
                <p class="hint">{{ __('Wolnych portów na węźle :node: :free. Personel może dodać konkretny numer portu i przekroczyć limit.', ['node' => $app->hypervisor?->name ?? '—', 'free' => $free]) }}</p>
            </div>
        @endif
    </div>
@endsection
