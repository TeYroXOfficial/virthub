@extends('layouts.panel')

@section('title', __('Pakiety'))

@section('content')
    <h1>{{ __('Pakiety zasobów') }}</h1>
    <p class="lede">{{ __('Oferta, spośród której klient wybiera przy zamawianiu maszyny.') }}</p>

    @include('panel.admin._nav')

    <details class="form-block card">
        <summary>{{ __('Dodaj pakiet') }}</summary>
        <form method="POST" action="{{ route('panel.admin.packages.store') }}" style="margin-top:12px">
            @csrf
            <div class="field">
                <label for="name">{{ __('Nazwa') }}</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}"
                       placeholder="{{ __('Standard') }}" required>
            </div>
            <div class="grid grid-3">
                <div class="field">
                    <label for="vcpu">{{ __('vCPU') }}</label>
                    <input id="vcpu" name="vcpu" type="text" value="{{ old('vcpu', 2) }}" required>
                </div>
                <div class="field">
                    <label for="cpu_limit_percent">{{ __('Limit CPU (%)') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
                    <input id="cpu_limit_percent" name="cpu_limit_percent" type="text" inputmode="numeric"
                           value="{{ old('cpu_limit_percent') }}" placeholder="{{ __('bez limitu') }}">
                    <div class="hint">{{ __('Procent jednego rdzenia dla całej maszyny (100 = 1 rdzeń), najwyżej vCPU × 100.') }}</div>
                </div>
                <div class="field">
                    <label for="ram_mb">{{ __('RAM (MB)') }}</label>
                    <input id="ram_mb" name="ram_mb" type="text" value="{{ old('ram_mb', 4096) }}" required>
                </div>
                <div class="field">
                    <label for="disk_gb">{{ __('Dysk (GB)') }}</label>
                    <input id="disk_gb" name="disk_gb" type="text" value="{{ old('disk_gb', 50) }}" required>
                </div>
                <div class="field">
                    <label for="bandwidth_gb">{{ __('Transfer (GB/mies.)') }}</label>
                    <input id="bandwidth_gb" name="bandwidth_gb" type="text"
                           value="{{ old('bandwidth_gb', 2000) }}" required>
                </div>
                <div class="field">
                    <label for="network_type">{{ __('Sieć') }}</label>
                    <select id="network_type" name="network_type">
                        <option value="public" @selected(old('network_type', 'public') === 'public')>{{ __('Publiczne adresy') }}</option>
                        <option value="nat" @selected(old('network_type') === 'nat')>{{ __('NAT (adres prywatny + porty)') }}</option>
                    </select>
                    <div class="hint">{{ __('Z jakich pul pochodzą adresy maszyny.') }}</div>
                </div>
                <div class="field">
                    <label for="ip_count">{{ __('Adresy IPv4') }}</label>
                    <input id="ip_count" name="ip_count" type="text" value="{{ old('ip_count', 1) }}" required>
                </div>
                <div class="field">
                    <label for="ipv6_count">{{ __('Adresy IPv6') }}</label>
                    <input id="ipv6_count" name="ipv6_count" type="text" value="{{ old('ipv6_count', 0) }}" required>
                    <div class="hint">{{ __('0 — maszyna bez IPv6.') }}</div>
                </div>
                <div class="field">
                    <label for="price_hint">{{ __('Cena (PLN)') }}</label>
                    <input id="price_hint" name="price_hint" type="text" value="{{ old('price_hint') }}"
                           placeholder="49.00">
                    <div class="hint">{{ __('Informacyjnie — fakturuje system rozliczeniowy.') }}</div>
                </div>
            </div>
            <button class="btn btn-primary" type="submit">{{ __('Dodaj pakiet') }}</button>
        </form>
    </details>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('Nazwa') }}</th><th>{{ __('Zasoby') }}</th><th>{{ __('Transfer') }}</th><th>{{ __('Sieć') }}</th>
                    <th>{{ __('Cena') }}</th><th>{{ __('Maszyny') }}</th><th>{{ __('Status') }}</th><th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($packages as $package)
                    <tr>
                        <td>
                            {{ $package->name }}
                            <div class="hint mono">{{ $package->slug }}</div>
                        </td>
                        <td class="num">
                            {{ __(':vcpu vCPU · :ramgb GB RAM · :disk_gb GB', ['vcpu' => $package->vcpu, 'ramgb' => $package->ramGb(), 'disk_gb' => $package->disk_gb]) }}
                            @if ($package->cpu_limit_percent)
                                <div class="hint">{{ __('limit CPU :percent%', ['percent' => $package->cpu_limit_percent]) }}</div>
                            @endif
                        </td>
                        <td class="num">{{ __(':bandwidth_gb GB', ['bandwidth_gb' => $package->bandwidth_gb]) }}</td>
                        <td class="num">
                            {{ __(':publ · :ip_count× IPv4', ['publ' => $package->usesNat() ? 'NAT' : __('publ.'), 'ip_count' => $package->ip_count]) }}@if($package->ipv6_count) {{ __('· :ipv6_count× IPv6', ['ipv6_count' => $package->ipv6_count]) }}@endif
                        </td>
                        <td class="num">
                            {{ $package->price_hint_cents ? number_format($package->price_hint_cents / 100, 2, ',', ' ').__(' zł') : '—' }}
                        </td>
                        <td class="num">{{ $package->servers_count }}</td>
                        <td>
                            <span class="pill {{ $package->is_active ? 'ok' : 'neutral' }}">
                                {{ $package->is_active ? __('w sprzedaży') : __('wycofany') }}
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('panel.admin.packages.toggle', $package) }}">
                                @csrf
                                <button class="btn" type="submit">
                                    {{ $package->is_active ? __('Wycofaj') : __('Przywróć') }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">{{ __('Brak pakietów — klient nie ma czego zamówić.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="hint">
        {{ __('Parametrów istniejącego pakietu nie da się zmienić. Działające maszyny mają skopiowane wartości z momentu zamówienia, więc zmiana w cenniku stworzyłaby rozjazd między tym, co klient widzi, a co faktycznie ma. Zamiast tego wycofaj pakiet i dodaj nowy.') }}
    </p>
@endsection
