@extends('layouts.panel')

@section('title', __('Migracja z Pterodactyla'))

@section('content')
    @include('panel.admin.apps._nav')

    @if ($created)
        <div class="alert alert-info">
            <strong>{{ __('Założono konta klientów — przekaż im dane logowania (widoczne tylko teraz):') }}</strong>
            <ul style="margin:8px 0 0">
                @foreach ($created as $account)
                    <li><span class="mono">{{ $account['email'] }}</span> · {{ __('hasło') }} <span class="mono">{{ $account['password'] }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif
    @error('servers') <div class="alert alert-error" style="white-space:pre-line">{{ $message }}</div> @enderror

    @if (! $credentials)
        <div class="card">
            <h3 class="card-title">{{ __('1. Połącz z panelem Pterodactyl') }}</h3>
            <p class="muted">{{ __('Panel przeniesie wybrane serwery razem z plikami, zmiennymi, zasobami i właścicielami. Serwery w Pterodactylu zostają nietknięte — po sprawdzeniu możesz je usunąć sam.') }}</p>
            <form method="POST" action="{{ route('panel.admin.apps.pterodactyl.connect') }}">
                @csrf
                <div class="field">
                    <label for="p-url">{{ __('Adres panelu Pterodactyl') }}</label>
                    <input id="p-url" name="url" type="url" required value="{{ old('url') }}" placeholder="https://panel.example.com">
                    @error('url') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <div class="grid-compact">
                    <div class="field">
                        <label for="p-app">{{ __('Klucz API aplikacji (ptla_…)') }}</label>
                        <input id="p-app" name="application_key" type="password" required autocomplete="off">
                        <div class="hint">{{ __('Admin → Application API → Create New. Uprawnienia odczytu: Servers, Nests, Users.') }}</div>
                        @error('application_key') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="p-client">{{ __('Klucz API klienta administratora (ptlc_…)') }}</label>
                        <input id="p-client" name="client_key" type="password" required autocomplete="off">
                        <div class="hint">{{ __('Konto administratora → API Credentials. Służy tylko do spakowania i pobrania plików serwerów.') }}</div>
                        @error('client_key') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Połącz i pokaż serwery') }}</button>
            </form>
        </div>
    @else
        <div class="card">
            <div class="setting-row" style="align-items:center">
                <div class="setting-text">
                    <h3>{{ __('Połączono z :url', ['url' => $credentials['url']]) }}</h3>
                    <p class="muted">{{ trans_choice(':count serwer do przeniesienia.|:count serwery do przeniesienia.|:count serwerów do przeniesienia.', count($servers)) }}</p>
                </div>
                <form method="POST" action="{{ route('panel.admin.apps.pterodactyl.disconnect') }}" style="margin:0">
                    @csrf <button class="btn btn-sm" type="submit">{{ __('Rozłącz') }}</button>
                </form>
            </div>
            @if ($error) <div class="alert alert-error">{{ $error }}</div> @endif
        </div>

        <form method="POST" action="{{ route('panel.admin.apps.pterodactyl.migrate') }}"
              data-confirm="{{ __('Przenieść zaznaczone serwery? Każdy zostanie spakowany w Pterodactylu i pobrany na węzeł VirtHub.') }}">
            @csrf
            <div class="card" style="padding:0">
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th style="width:32px"><input type="checkbox" data-check-all aria-label="{{ __('Zaznacz wszystkie') }}" style="width:auto"></th>
                            <th>{{ __('Serwer') }}</th><th>{{ __('Właściciel') }}</th><th>{{ __('Egg') }}</th><th>{{ __('Zasoby') }}</th><th>{{ __('Stan') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($servers as $server)
                            @php
                                $done = isset($migrated[$server['identifier']]);
                                $limits = $server['limits'] ?? [];
                            @endphp
                            <tr>
                                <td><input type="checkbox" name="servers[]" value="{{ $server['identifier'] }}" data-check style="width:auto"
                                           @disabled($done) aria-label="{{ __('Zaznacz :name', ['name' => $server['name']]) }}"></td>
                                <td><strong>{{ $server['name'] }}</strong><div class="hint mono">{{ $server['identifier'] }}</div></td>
                                <td class="muted">{{ $server['relationships']['user']['attributes']['email'] ?? '—' }}</td>
                                <td>{{ $server['relationships']['egg']['attributes']['name'] ?? '—' }}</td>
                                <td class="num" style="white-space:nowrap">
                                    {{ ($limits['memory'] ?? 0) ? $limits['memory'].' MB' : __('RAM bez limitu') }} ·
                                    {{ ($limits['disk'] ?? 0) ? $limits['disk'].' MB' : __('dysk bez limitu') }}
                                </td>
                                <td>
                                    @if ($done)
                                        <span class="pill ok">{{ __('przeniesiony') }}</span>
                                    @elseif ($server['suspended'] ?? false)
                                        <span class="pill warning">{{ __('zawieszony') }}</span>
                                    @else
                                        <span class="pill neutral">{{ __('do przeniesienia') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="muted" style="text-align:center; padding:24px">{{ __('Brak serwerów w panelu Pterodactyl.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="grid-compact">
                    <div class="field">
                        <label for="p-node">{{ __('Węzeł docelowy') }}</label>
                        <select id="p-node" name="node">
                            <option value="">{{ __('automatycznie (najwięcej wolnej pamięci)') }}</option>
                            @foreach ($nodes as $node)
                                <option value="{{ $node->id }}">{{ $node->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <label class="check-line"><input type="checkbox" name="stop" value="1" checked>
                    {{ __('Zatrzymaj serwer w Pterodactylu przed kopiowaniem (spójny świat i bazy)') }}</label>
                <p class="hint">
                    {{ __('Serwer dostanie nowy port na węźle VirtHub — klienci łączą się pod nowym adresem. Brakujące konta klientów powstaną automatycznie (dane logowania pokażą się po przeniesieniu). Serwery bez limitu RAM/dysku dostają 4096 MB RAM i 20480 MB dysku.') }}
                </p>
                <button class="btn btn-primary" type="submit"><x-icon name="refresh" :size="15"/> {{ __('Przenieś zaznaczone') }}</button>
            </div>
        </form>

        <script>
            document.querySelector('[data-check-all]')?.addEventListener('change', (e) => {
                document.querySelectorAll('[data-check]:not(:disabled)').forEach((b) => { b.checked = e.target.checked; });
            });
        </script>
    @endif
@endsection
