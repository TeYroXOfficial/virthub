@extends('layouts.panel')

@section('title', __('Moduły billingowe'))

@php
    $systems = [
        'whmcs' => [
            'name' => 'WHMCS',
            'target' => 'modules/servers/virthub',
            'steps' => [
                __('Rozpakuj archiwum w katalogu głównym WHMCS (powstanie modules/servers/virthub).'),
                __('Setup → Products/Services → Servers → Add New Server: moduł „VirtHub”, Hostname = adres panelu, Access Hash = token API, zaznacz „Secure”. Kliknij „Test Connection”.'),
                __('Produkt → Module Settings: moduł VirtHub, wybierz pakiet, domyślny system i lokalizację z list (pobierane z panelu).'),
                __('Opcjonalnie: opcja konfigurowalna „Operating System” z wartościami „id|nazwa” (np. 3|Debian 13) i pole własne „SSH Key” — klient wybiera system i klucz przy zamówieniu.'),
            ],
        ],
        'blesta' => [
            'name' => 'Blesta',
            'target' => 'components/modules/virthub',
            'steps' => [
                __('Rozpakuj archiwum w katalogu głównym Blesty (powstanie components/modules/virthub).'),
                __('Settings → Company → Modules → Available → VirtHub → Install, potem „Add server”: adres panelu i token API (połączenie jest sprawdzane przy zapisie).'),
                __('Packages → Create Package: moduł VirtHub, pakiet, domyślny system i lokalizacja. Klient wybiera nazwę hosta, system i klucz SSH przy zamówieniu.'),
            ],
        ],
        'paymenter' => [
            'name' => 'Paymenter',
            'target' => 'extensions/Servers/VirtHub',
            'steps' => [
                __('Rozpakuj archiwum w katalogu głównym Paymentera (powstanie extensions/Servers/VirtHub) i uruchom composer dump-autoload.'),
                __('Admin → Servers → Create: rozszerzenie VirtHub, adres panelu i token API.'),
                __('Produkt → serwer VirtHub: pakiet, domyślny system i lokalizacja. Przy zamówieniu klient podaje nazwę hosta, system i klucz SSH.'),
            ],
        ],
    ];
@endphp

@section('content')
    <div class="page-header"><div><h1>{{ __('Moduły billingowe') }}</h1>
        <p class="lede">{{ __('Sprzedawaj VPS-y z VirtHub w WHMCS, Blesta albo Paymenter. Moduł zakłada, zawiesza, odwiesza, usuwa i zmienia pakiet maszyny, a klient steruje serwerem prosto z panelu billingu: zasilanie, konsola, reinstalacja systemu, reset hasła, transfer i logowanie do VirtHub jednym kliknięciem.') }}</p></div></div>

    <section class="card">
        <h2 class="card-title"><x-icon name="key" :size="16"/> {{ __('1. Token API') }}</h2>
        <p>{{ __('Zaloguj się kontem personelu przeznaczonym dla billingu i utwórz token z zakresem „billing” (Moje konto → Bezpieczeństwo → Tokeny API). Token z tym zakresem nie ma dostępu do niczego poza API rozliczeniowym.') }}</p>
        <dl class="kv">
            <dt>{{ __('Adres panelu') }}</dt><dd><code>{{ $panelUrl }}</code></dd>
            <dt>{{ __('API') }}</dt><dd><a href="{{ route('api-docs') }}#ep-billing.provision">{{ __('Dokumentacja endpointów rozliczeniowych') }}</a></dd>
        </dl>
        <a class="btn" href="{{ route('panel.account.security') }}">{{ __('Utwórz token') }}</a>
        <p class="hint">{{ __('Jeśli konto ma listę zaufanych adresów IP, dodaj do niej adres serwera z systemem billingowym.') }}</p>
    </section>

    <h2>{{ __('2. Moduł') }}</h2>
    <div class="grid grid-3">
        @foreach ($systems as $key => $system)
            <section class="card">
                <h3 class="card-title" style="display:flex; justify-content:space-between; align-items:center">
                    <span><x-icon name="puzzle" :size="16"/> {{ $system['name'] }}</span>
                    <span class="pill neutral plain">v{{ $version }}</span>
                </h3>
                @if ($available[$key])
                    <a class="btn btn-primary" href="{{ route('panel.admin.billing-modules.download', $key) }}"><x-icon name="download" :size="14"/> {{ __('Pobierz moduł') }}</a>
                @else
                    <p class="muted">{{ __('Brak katalogu integrations/ — zaktualizuj panel z repozytorium.') }}</p>
                @endif
                <ol style="padding-left:18px; margin-top:12px">
                    @foreach ($system['steps'] as $step) <li style="margin-bottom:6px">{{ $step }}</li> @endforeach
                </ol>
                <p class="hint"><code>{{ $system['target'] }}</code></p>
            </section>
        @endforeach
    </div>

    <section class="card">
        <h2 class="card-title"><x-icon name="list" :size="16"/> {{ __('Co potrafią moduły') }}</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Funkcja') }}</th><th>WHMCS</th><th>Blesta</th><th>Paymenter</th></tr></thead>
                <tbody>
                @foreach ([
                    __('Założenie, zawieszenie, odwieszenie, usunięcie'),
                    __('Zmiana pakietu (upgrade / downgrade)'),
                    __('Listy pakietów, systemów i lokalizacji z panelu'),
                    __('Wybór systemu, nazwy hosta i klucza SSH przy zamówieniu'),
                    __('Start, zamknięcie, restart, wyłączenie zasilania'),
                    __('Konsola w przeglądarce'),
                    __('Reinstalacja systemu'),
                    __('Reset hasła i zapis hasła w danych usługi'),
                    __('Stan, adresy IP, zasoby i transfer'),
                    __('Logowanie do VirtHub jednym kliknięciem (SSO)'),
                ] as $feature)
                    <tr><td>{{ $feature }}</td><td>✓</td><td>✓</td><td>✓</td></tr>
                @endforeach
                <tr><td>{{ __('Zużycie transferu w statystykach billingu') }}</td><td>✓</td><td>—</td><td>—</td></tr>
                </tbody>
            </table>
        </div>
        <p class="hint">{{ __('Usługa w VirtHub jest wiązana z billingiem identyfikatorem (np. whmcs-123) — ponowione zlecenie nie tworzy drugiej maszyny. Moduły mówią językiem systemu billingowego (polski albo angielski), w tym komunikaty błędów panelu.') }}</p>
    </section>
@endsection
