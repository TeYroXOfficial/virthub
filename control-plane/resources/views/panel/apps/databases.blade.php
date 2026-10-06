@extends('layouts.panel')

@section('title', __('Bazy danych — :name', ['name' => $app->name]))

@php
    $staff = auth()->user()->can('manage', $app);
    $count = $app->databases->count();
    $canCreate = $staff || $count < $limit;
@endphp

@section('content')
    @include('panel.apps._header')
    @error('database') <div class="alert alert-error">{{ $message }}</div> @enderror
    @error('confirm') <div class="alert alert-error">{{ $message }}</div> @enderror

    <div class="card flush dash-section">
        <div class="dash-head">
            <h3 class="card-title" style="margin:0"><x-icon name="database" :size="16"/> {{ __('Bazy danych') }}
                <span class="pill neutral plain">{{ __(':count z :limit', ['count' => $count, 'limit' => $limit]) }}</span></h3>
        </div>
        @forelse ($app->databases as $db)
            @php $size = $sizes[$db->id] ?? null; @endphp
            <div class="db-item">
                <div class="db-head">
                    <div>
                        <strong class="mono">{{ $db->database }}</strong>
                        <span class="hint">{{ $db->host->name }}@if ($size !== null) · {{ \Illuminate\Support\Number::fileSize($size, 1) }}@endif</span>
                    </div>
                    @can('operate', $app)
                        <div class="btn-row" style="margin:0">
                            @if ($db->browsable())
                                <a class="btn btn-sm btn-primary" href="{{ route('panel.apps.databases.browse', [$app, $db]) }}"><x-icon name="table" :size="14"/> {{ __('Zarządzaj') }}</a>
                            @endif
                            <form method="POST" action="{{ route('panel.apps.databases.password', [$app, $db]) }}" style="margin:0"
                                  data-confirm="{{ __('Ustawić nowe hasło? Aplikacja straci połączenie, dopóki nie zmienisz hasła w jej konfiguracji.') }}">
                                @csrf
                                <button class="btn btn-sm" type="submit"><x-icon name="key" :size="14"/> {{ __('Nowe hasło') }}</button>
                            </form>
                            <button class="btn btn-sm btn-ghost" type="button" onclick="document.getElementById('db-del-{{ $db->id }}').showModal()" aria-label="{{ __('Usuń') }}"><x-icon name="trash" :size="14"/></button>
                        </div>
                        <dialog class="modal" id="db-del-{{ $db->id }}" style="padding:22px 24px; width:min(440px, calc(100vw - 32px))">
                            <form method="POST" action="{{ route('panel.apps.databases.destroy', [$app, $db]) }}">
                                @csrf @method('DELETE')
                                <h3 class="card-title">{{ __('Usunąć bazę :name?', ['name' => $db->database]) }}</h3>
                                <p class="muted">{{ __('Baza zostanie usunięta razem z wszystkimi danymi i użytkownikiem. Tego nie da się cofnąć — najpierw pobierz eksport, jeśli dane mogą się przydać.') }}</p>
                                <div class="field"><label for="dc-{{ $db->id }}">{{ __('Wpisz nazwę bazy') }}</label>
                                    <input id="dc-{{ $db->id }}" name="confirm" autocomplete="off" required placeholder="{{ $db->database }}"></div>
                                <div class="btn-row" style="justify-content:flex-end">
                                    <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('Anuluj') }}</button>
                                    <button class="btn btn-danger" type="submit">{{ __('Usuń bazę') }}</button>
                                </div>
                            </form>
                        </dialog>
                    @endcan
                </div>
                <dl class="kv db-kv">
                    <dt>{{ __('Host') }}</dt><dd class="mono copyable" data-copy="{{ $db->host->clientHost() }}:{{ $db->host->port }}" title="{{ __('Kliknij, żeby skopiować') }}">{{ $db->host->clientHost() }}:{{ $db->host->port }}</dd>
                    <dt>{{ __('Baza') }}</dt><dd class="mono copyable" data-copy="{{ $db->database }}" title="{{ __('Kliknij, żeby skopiować') }}">{{ $db->database }}</dd>
                    <dt>{{ __('Użytkownik') }}</dt><dd class="mono copyable" data-copy="{{ $db->username }}" title="{{ __('Kliknij, żeby skopiować') }}">{{ $db->username }}</dd>
                    <dt>{{ __('Hasło') }}</dt>
                    <dd>
                        @can('operate', $app)
                            <span class="mono copyable db-pass" data-copy="{{ $db->secret() }}" data-secret="{{ $db->secret() }}" title="{{ __('Kliknij, żeby skopiować') }}">••••••••••••</span>
                            <button class="btn btn-sm btn-ghost" type="button" data-reveal>{{ __('Pokaż') }}</button>
                        @else
                            <span class="muted">{{ __('ukryte') }}</span>
                        @endcan
                    </dd>
                    <dt>{{ __('Połączenia z') }}</dt><dd class="mono">{{ $db->remote === '%' ? __('dowolnego hosta (%)') : $db->remote }}</dd>
                    <dt>JDBC</dt><dd class="mono copyable" data-copy="{{ $db->jdbcUrl() }}" title="{{ __('Kliknij, żeby skopiować') }}" style="word-break:break-all">{{ $db->jdbcUrl() }}</dd>
                </dl>
                @unless ($db->browsable())
                    <p class="hint">{{ __('Przeglądarka w panelu działa tylko dla baz przyjmujących połączenia z dowolnego hosta (%).') }}</p>
                @endunless
            </div>
        @empty
            <p class="muted" style="padding:16px 20px; margin:0">
                @if ($limit > 0 || $staff)
                    {{ __('Aplikacja nie ma jeszcze baz danych. Utwórz bazę poniżej i wpisz dane połączenia w konfiguracji serwera (np. pluginu).') }}
                @else
                    {{ __('Plan tej aplikacji nie obejmuje baz danych.') }}
                @endif
            </p>
        @endforelse
    </div>

    <div class="grid grid-2" style="margin-top:16px">
        @can('operate', $app)
            <div class="card">
                <h3 class="card-title">{{ __('Nowa baza') }}</h3>
                @if (! $hostAvailable && ! ($staff && $hosts->isNotEmpty()))
                    <p class="muted">{{ __('Brak dostępnego serwera baz danych dla tej aplikacji. Skontaktuj się z obsługą.') }}</p>
                @elseif (! $canCreate)
                    <p class="muted">{{ __('Wykorzystano limit baz danych (:limit).', ['limit' => $limit]) }}</p>
                @else
                    <form method="POST" action="{{ route('panel.apps.databases.store', $app) }}">
                        @csrf
                        <div class="field">
                            <label for="db-name">{{ __('Nazwa') }}</label>
                            <div class="input-prefix"><span class="mono">s{{ $app->id }}_</span><input id="db-name" name="name" required maxlength="40" pattern="[a-z0-9_]+" value="{{ old('name') }}" placeholder="luckperms"></div>
                            @error('name') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                        </div>
                        <div class="field">
                            <label for="db-remote">{{ __('Połączenia z') }}</label>
                            <input id="db-remote" name="remote" maxlength="64" value="{{ old('remote', '%') }}">
                            <div class="hint">{{ __('% — z dowolnego adresu (wymagane dla przeglądarki w panelu). Możesz ograniczyć do jednego IP, np. adresu serwera.') }}</div>
                            @error('remote') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                        </div>
                        @if ($staff && $hosts->isNotEmpty())
                            <div class="field">
                                <label for="db-host">{{ __('Serwer baz') }} <span class="pill neutral">{{ __('personel') }}</span></label>
                                <select id="db-host" name="host">
                                    <option value="">{{ __('automatycznie') }}</option>
                                    @foreach ($hosts as $h)
                                        <option value="{{ $h->id }}" @selected(old('host') == $h->id)>{{ $h->name }} ({{ $h->host }})</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <button class="btn btn-primary" type="submit"><x-icon name="plus" :size="14"/> {{ __('Utwórz bazę') }}</button>
                    </form>
                @endif
            </div>
        @endcan
        @if ($staff)
            <div class="card">
                <h3 class="card-title">{{ __('Limit baz') }} <span class="pill neutral">{{ __('personel') }}</span></h3>
                <form method="POST" action="{{ route('panel.apps.databases.limit', $app) }}" class="filter-bar">
                    @csrf @method('PUT')
                    <div class="field" style="margin:0">
                        <label for="dl">{{ __('Limit dla tej aplikacji') }}</label>
                        <input id="dl" type="number" name="database_limit" min="0" max="100" value="{{ $app->database_limit }}" placeholder="{{ __('z planu: :n', ['n' => $app->plan?->databases ?? 0]) }}" style="max-width:150px">
                    </div>
                    <button class="btn" type="submit">{{ __('Zapisz') }}</button>
                </form>
                <p class="hint">{{ __('Personel może tworzyć bazy ponad limit i wybrać serwer baz.') }}</p>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-reveal]').forEach((btn) => btn.addEventListener('click', () => {
            const el = btn.previousElementSibling;
            const shown = el.dataset.shown === '1';
            el.textContent = shown ? '••••••••••••' : el.dataset.secret;
            el.dataset.shown = shown ? '0' : '1';
            btn.textContent = shown ? @json(__('Pokaż')) : @json(__('Ukryj'));
        }));
    </script>
@endpush
