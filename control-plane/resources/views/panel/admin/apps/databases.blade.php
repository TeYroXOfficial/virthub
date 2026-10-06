@extends('layouts.panel')

@section('title', __('Bazy danych aplikacji'))

@php $isAdmin = auth()->user()->isAdmin(); @endphp

@section('content')
    @include('panel.admin.apps._nav')
    @error('host') <div class="alert alert-error">{{ $message }}</div> @enderror

    @if ($isAdmin)
        <details class="card" style="margin-bottom:16px" @if ($hosts->isEmpty() || old('_new')) open @endif>
            <summary><strong>{{ __('Nowy serwer baz danych') }}</strong></summary>
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
