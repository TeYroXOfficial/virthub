@extends('layouts.panel')

@section('title', 'Pakiety')

@section('content')
    <h1>Pakiety zasobów</h1>
    <p class="lede">Oferta, spośród której klient wybiera przy zamawianiu maszyny.</p>

    @include('panel.admin._nav')

    <details class="form-block card">
        <summary>Dodaj pakiet</summary>
        <form method="POST" action="{{ route('panel.admin.packages.store') }}" style="margin-top:12px">
            @csrf
            <div class="field">
                <label for="name">Nazwa</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}"
                       placeholder="Standard" required>
            </div>
            <div class="grid grid-3">
                <div class="field">
                    <label for="vcpu">vCPU</label>
                    <input id="vcpu" name="vcpu" type="text" value="{{ old('vcpu', 2) }}" required>
                </div>
                <div class="field">
                    <label for="ram_mb">RAM (MB)</label>
                    <input id="ram_mb" name="ram_mb" type="text" value="{{ old('ram_mb', 4096) }}" required>
                </div>
                <div class="field">
                    <label for="disk_gb">Dysk (GB)</label>
                    <input id="disk_gb" name="disk_gb" type="text" value="{{ old('disk_gb', 50) }}" required>
                </div>
                <div class="field">
                    <label for="bandwidth_gb">Transfer (GB/mies.)</label>
                    <input id="bandwidth_gb" name="bandwidth_gb" type="text"
                           value="{{ old('bandwidth_gb', 2000) }}" required>
                </div>
                <div class="field">
                    <label for="network_type">Sieć</label>
                    <select id="network_type" name="network_type">
                        <option value="public" @selected(old('network_type', 'public') === 'public')>Publiczne adresy</option>
                        <option value="nat" @selected(old('network_type') === 'nat')>NAT (adres prywatny + porty)</option>
                    </select>
                    <div class="hint">Z jakich pul pochodzą adresy maszyny.</div>
                </div>
                <div class="field">
                    <label for="ip_count">Adresy IPv4</label>
                    <input id="ip_count" name="ip_count" type="text" value="{{ old('ip_count', 1) }}" required>
                </div>
                <div class="field">
                    <label for="ipv6_count">Adresy IPv6</label>
                    <input id="ipv6_count" name="ipv6_count" type="text" value="{{ old('ipv6_count', 0) }}" required>
                    <div class="hint">0 — maszyna bez IPv6.</div>
                </div>
                <div class="field">
                    <label for="price_hint">Cena (PLN)</label>
                    <input id="price_hint" name="price_hint" type="text" value="{{ old('price_hint') }}"
                           placeholder="49.00">
                    <div class="hint">Informacyjnie — fakturuje system rozliczeniowy.</div>
                </div>
            </div>
            <button class="btn btn-primary" type="submit">Dodaj pakiet</button>
        </form>
    </details>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Nazwa</th><th>Zasoby</th><th>Transfer</th><th>Sieć</th>
                    <th>Cena</th><th>Maszyny</th><th>Status</th><th></th>
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
                            {{ $package->vcpu }} vCPU · {{ $package->ramGb() }} GB RAM · {{ $package->disk_gb }} GB
                        </td>
                        <td class="num">{{ $package->bandwidth_gb }} GB</td>
                        <td class="num">
                            {{ $package->usesNat() ? 'NAT' : 'publ.' }} ·
                            {{ $package->ip_count }}× IPv4@if($package->ipv6_count) · {{ $package->ipv6_count }}× IPv6@endif
                        </td>
                        <td class="num">
                            {{ $package->price_hint_cents ? number_format($package->price_hint_cents / 100, 2, ',', ' ').' zł' : '—' }}
                        </td>
                        <td class="num">{{ $package->servers_count }}</td>
                        <td>
                            <span class="pill {{ $package->is_active ? 'ok' : 'neutral' }}">
                                {{ $package->is_active ? 'w sprzedaży' : 'wycofany' }}
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('panel.admin.packages.toggle', $package) }}">
                                @csrf
                                <button class="btn" type="submit">
                                    {{ $package->is_active ? 'Wycofaj' : 'Przywróć' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Brak pakietów — klient nie ma czego zamówić.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="hint">
        Parametrów istniejącego pakietu nie da się zmienić. Działające maszyny mają
        skopiowane wartości z momentu zamówienia, więc zmiana w cenniku stworzyłaby
        rozjazd między tym, co klient widzi, a co faktycznie ma. Zamiast tego wycofaj
        pakiet i dodaj nowy.
    </p>
@endsection
