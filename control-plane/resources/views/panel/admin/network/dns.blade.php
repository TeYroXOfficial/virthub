@extends('layouts.panel')

@section('title', __('rDNS (PowerDNS)'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('rDNS (PowerDNS)') }}</h1>
            <p class="lede">{{ __('Rekordy PTR adresów ustawiane przez administratorów i klientów trafiają do Twojego serwera PowerDNS przez jego API.') }}</p>
        </div>
    </div>

    @unless ($configured)
        <div class="alert alert-warning">
            {{ __('PowerDNS nie jest podłączony — rDNS zapisuje się tylko w panelu. Żeby działał w DNS, podłącz PowerDNS z delegowanymi strefami odwrotnymi albo przepisz nazwy u dostawcy adresów.') }}
        </div>
    @endunless

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title"><x-icon name="network" :size="16"/> {{ __('Połączenie z PowerDNS') }}</h3>
            <form method="POST" action="{{ route('panel.admin.network.dns.update') }}">
                @csrf @method('PUT')
                <div class="field">
                    <label for="d-url">{{ __('Adres API') }}</label>
                    <input id="d-url" name="pdns_url" type="url" value="{{ old('pdns_url', $dns['url']) }}" placeholder="https://ns1.example.com:8081">
                    <div class="hint">{{ __('Wbudowany serwer HTTP PowerDNS (api=yes, webserver=yes). Bez /api/v1 na końcu.') }}</div>
                </div>
                <div class="field">
                    <label for="d-key">{{ __('Klucz API') }}</label>
                    <input id="d-key" name="pdns_key" type="password" autocomplete="new-password"
                           placeholder="{{ $dns['has_key'] ? __('zapisany — zostaw puste, żeby nie zmieniać') : 'api-key z pdns.conf' }}">
                </div>
                <div class="grid-compact">
                    <div class="field">
                        <label for="d-server">{{ __('Serwer') }}</label>
                        <input id="d-server" name="pdns_server" type="text" value="{{ old('pdns_server', $dns['server']) }}">
                    </div>
                    <div class="field">
                        <label for="d-ttl">{{ __('TTL rekordów (s)') }}</label>
                        <input id="d-ttl" name="ttl" type="text" inputmode="numeric" value="{{ old('ttl', $dns['ttl']) }}">
                    </div>
                </div>
                <label class="check-line" style="margin-bottom:12px">
                    <input type="checkbox" name="require_forward" value="1" @checked(old('require_forward', $dns['require_forward']))>
                    {{ __('Klient może ustawić tylko nazwę, która rekordem A/AAAA wskazuje na ten adres (zalecane)') }}
                </label>
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="refresh" :size="16"/> {{ __('Sprawdzenie') }}</h3>
            <p class="muted">{{ __('Panel pobierze listę stref odwrotnych (in-addr.arpa, ip6.arpa). Rekord PTR trafia do najdłuższej strefy pasującej do adresu — strefy muszą istnieć w PowerDNS i być oddelegowane do niego przez dostawcę adresów.') }}</p>
            <form method="POST" action="{{ route('panel.admin.network.dns.test') }}">
                @csrf
                <button class="btn" type="submit" @disabled(! $configured)>{{ __('Sprawdź połączenie') }}</button>
            </form>
            <p class="hint" style="margin-top:14px">{{ __('Przy zwolnieniu adresu (usunięcie maszyny) panel usuwa jego PTR, żeby następny klient nie dostał cudzej nazwy.') }}</p>
        </div>
    </div>
@endsection
