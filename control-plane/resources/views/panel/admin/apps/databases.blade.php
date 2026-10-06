@extends('layouts.panel')

@section('title', __('Bazy danych aplikacji'))

@php $isAdmin = auth()->user()->isAdmin(); @endphp

@section('content')
    @include('panel.admin.apps._nav')
    @error('host') <div class="alert alert-error">{{ $message }}</div> @enderror

    @if ($installPending)
        @push('head') <meta http-equiv="refresh" content="10"> @endpush
    @endif

    @if ($isAdmin)
        <div class="card" style="margin-bottom:16px">
            <h3 class="card-title"><x-icon name="database" :size="16"/> {{ __('Zainstaluj MariaDB na węźle') }}</h3>
            <p class="muted">{{ __('Jedno kliknięcie: węzeł instaluje MariaDB, a panel sam dodaje serwer baz. Konto administracyjne panelu działa tylko z adresu panelu.') }}</p>
            @error('install') <div class="alert alert-error">{{ $message }}</div> @enderror
            <form method="POST" action="{{ route('panel.admin.apps.database-hosts.install') }}" class="filter-bar">
                @csrf
                <div class="field" style="margin:0">
                    <label for="mi-node">{{ __('Węzeł') }}</label>
                    <select id="mi-node" name="hypervisor_id" required>
                        @foreach ($nodes as $node)
                            <option value="{{ $node->id }}" @disabled(! $node->isOnline())>{{ $node->name }}@unless ($node->isOnline()) ({{ __('offline') }})@endunless</option>
                        @endforeach
                    </select>
                </div>
                <label class="check-line" style="margin:0"><input type="checkbox" name="open_firewall" value="1">
                    {{ __('Otwórz port bazy w zaporze węzła (ufw)') }}</label>
                <button class="btn btn-primary" type="submit" @disabled($nodes->isEmpty())>{{ __('Zainstaluj') }}</button>
            </form>
            <p class="hint">{{ __('Aplikacje i klienci łączą się z bazą po porcie 3306 — jeśli na węźle działa zapora, zaznacz otwarcie portu albo zrób to sam. Węzeł musi być zaktualizowany do najnowszej wersji.') }}</p>

            @if ($installs)
                <div class="table-wrap" style="margin-top:8px">
                    <table>
                        <thead><tr><th>{{ __('Węzeł') }}</th><th>{{ __('Status') }}</th><th>{{ __('Szczegóły') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($installs as $nodeId => $install)
                            @php
                                $pill = ['queued' => 'neutral', 'running' => 'info', 'done' => 'ok', 'warning' => 'warning', 'failed' => 'critical'][$install['state']] ?? 'neutral';
                                $label = ['queued' => __('w kolejce'), 'running' => __('instalacja…'), 'done' => __('gotowe'), 'warning' => __('wymaga uwagi'), 'failed' => __('błąd')][$install['state']] ?? $install['state'];
                            @endphp
                            <tr>
                                <td>{{ $nodes->firstWhere('id', $nodeId)?->name ?? '#'.$nodeId }}</td>
                                <td><span class="pill {{ $pill }}">{{ $label }}</span></td>
                                <td>{{ $install['message'] ?? '—' }} <span class="hint">{{ \Illuminate\Support\Carbon::createFromTimestamp($install['at'])->diffForHumans() }}</span></td>
                                <td style="text-align:right">
                                    @unless (in_array($install['state'], ['queued', 'running'], true))
                                        <form method="POST" action="{{ route('panel.admin.apps.database-hosts.install.dismiss', $nodeId) }}" style="margin:0">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-ghost" type="submit">{{ __('Ukryj') }}</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <details class="card" style="margin-bottom:16px" @if (old('_new')) open @endif>
            <summary><strong>{{ __('Dodaj istniejący serwer ręcznie') }}</strong></summary>
            <form method="POST" action="{{ route('panel.admin.apps.database-hosts.store') }}" style="margin-top:14px" autocomplete="off">
                @csrf
                <input type="hidden" name="_new" value="1">
                @include('panel.admin.apps._db-host-fields', ['h' => null, 'v' => fn ($f, $d = null) => old($f, $d)])
                <button class="btn btn-primary" type="submit">{{ __('Dodaj serwer') }}</button>
            </form>
        </details>
    @endif

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0"><x-icon name="database" :size="16"/> {{ __('Serwery baz danych') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Nazwa') }}</th><th>{{ __('Połączenie panelu') }}</th><th>{{ __('Adres dla klientów') }}</th><th>{{ __('Węzeł') }}</th><th class="num">{{ __('Bazy') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($hosts as $host)
                    <tr>
                        <td><strong>{{ $host->name }}</strong><div class="hint mono">{{ $host->username }}</div></td>
                        <td class="mono">{{ $host->host }}:{{ $host->port }}</td>
                        <td class="mono">{{ $host->clientHost() }}</td>
                        <td>{{ $host->hypervisor?->name ?? __('wszystkie') }}</td>
                        <td class="num">{{ $host->databases_count }}@if ($host->max_databases) / {{ $host->max_databases }}@endif</td>
                        <td><span class="pill {{ $host->is_active ? 'ok' : 'neutral' }}">{{ $host->is_active ? __('aktywny') : __('wyłączony') }}</span></td>
                        <td style="text-align:right; white-space:nowrap">
                            @if ($isAdmin)
                                <form method="POST" action="{{ route('panel.admin.apps.database-hosts.test', $host) }}" style="display:inline">
                                    @csrf
                                    <button class="btn btn-sm" type="submit">{{ __('Test') }}</button>
                                </form>
                                @php $bag = $errors->getBag('edit_'.$host->id); $mine = $bag->any(); @endphp
                                <button class="btn btn-sm" type="button" onclick="document.getElementById('dbh-edit-{{ $host->id }}').showModal()">{{ __('Edytuj') }}</button>
                                <dialog class="modal edit-modal" id="dbh-edit-{{ $host->id }}" @if ($mine) data-open @endif>
                                    <form method="POST" action="{{ route('panel.admin.apps.database-hosts.update', $host) }}" autocomplete="off">
                                        @csrf @method('PUT')
                                        <h3 class="card-title">{{ __('Edytuj serwer :name', ['name' => $host->name]) }}</h3>
                                        @if ($mine) <div class="alert alert-error"><ul>@foreach ($bag->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div> @endif
                                        @include('panel.admin.apps._db-host-fields', ['h' => $host, 'v' => fn ($f, $d = null) => $mine ? old($f, $d) : $d])
                                        <div class="btn-row" style="justify-content:flex-end">
                                            <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('Anuluj') }}</button>
                                            <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
                                        </div>
                                    </form>
                                </dialog>
                                @if ($host->databases_count === 0)
                                    <form method="POST" action="{{ route('panel.admin.apps.database-hosts.destroy', $host) }}" style="display:inline"
                                          data-confirm="{{ __('Usunąć serwer baz :name z panelu?', ['name' => $host->name]) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń') }}</button>
                                    </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted" style="text-align:center; padding:24px">{{ __('Brak serwerów baz danych — dodaj pierwszy, żeby klienci mogli tworzyć bazy.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card flush" style="margin-top:16px">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Bazy klientów') }}</h3></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Baza') }}</th><th>{{ __('Użytkownik') }}</th><th>{{ __('Aplikacja') }}</th><th>{{ __('Właściciel') }}</th><th>{{ __('Serwer') }}</th><th>{{ __('Utworzona') }}</th></tr></thead>
                <tbody>
                @forelse ($databases as $db)
                    <tr>
                        <td class="mono">{{ $db->database }}</td>
                        <td class="mono">{{ $db->username }}<span class="hint">&#64;{{ $db->remote }}</span></td>
                        <td>@if ($db->app)<a href="{{ route('panel.apps.databases', $db->app) }}">{{ $db->app->name }}</a>@else — @endif</td>
                        <td>{{ $db->app?->user?->email ?? '—' }}</td>
                        <td>{{ $db->host->name }}</td>
                        <td>{{ $db->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted" style="text-align:center; padding:24px">{{ __('Klienci nie mają jeszcze baz danych.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $databases->links() }}

    <div class="card" style="margin-top:16px">
        <h3 class="card-title">{{ __('Jak przygotować serwer baz') }}</h3>
        <p class="muted">{{ __('Na serwerze MySQL/MariaDB (np. na węźle) utwórz konto administracyjne dostępne z adresu panelu i ustaw nasłuchiwanie na adresie, z którym łączą się aplikacje:') }}</p>
        <pre class="mono" style="white-space:pre-wrap; font-size:12px">CREATE USER 'virthub'@'IP_PANELU' IDENTIFIED BY 'silne-haslo';
GRANT ALL PRIVILEGES ON *.* TO 'virthub'@'IP_PANELU' WITH GRANT OPTION;
FLUSH PRIVILEGES;
# /etc/mysql/mariadb.conf.d/50-server.cnf: bind-address = 0.0.0.0</pre>
        <p class="hint">{{ __('Przeglądarka baz w panelu łączy się kontem klienta, więc port bazy musi być osiągalny z panelu. Aplikacje w kontenerach łączą się z adresem dla klientów.') }}</p>
    </div>
    @include('panel.admin._edit-modal-script')
@endsection
