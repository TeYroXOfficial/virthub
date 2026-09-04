@extends('layouts.panel')

@section('title', 'Adresy IP')

@section('content')
    <h1>Pule adresów</h1>
    <p class="lede">Adresy przydzielane maszynom. Bez wolnego adresu zamówienie zostanie odrzucone.</p>

    @include('panel.admin._nav')

    <details class="form-block card" @if($pools->isEmpty()) open @endif>
        <summary>Zaimportuj pulę</summary>
        <form method="POST" action="{{ route('panel.admin.ip-pools.store') }}" style="margin-top:12px">
            @csrf
            <div class="field">
                <label for="hypervisor_id">Węzeł</label>
                <select id="hypervisor_id" name="hypervisor_id" required>
                    @forelse ($hypervisors as $node)
                        <option value="{{ $node->id }}">{{ $node->name }}</option>
                    @empty
                        <option value="" disabled>Najpierw dodaj hypervisor</option>
                    @endforelse
                </select>
                <div class="hint">Adresy są przypisane do węzła — maszyna dostaje adres z puli swojego węzła.</div>
            </div>

            <div class="grid grid-2">
                <div class="field">
                    <label for="name">Nazwa puli</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}"
                           placeholder="Pula podstawowa" required>
                </div>
                <div class="field">
                    <label for="cidr">Podsieć (CIDR)</label>
                    <input id="cidr" name="cidr" type="text" value="{{ old('cidr') }}"
                           placeholder="203.0.113.0/24" required>
                </div>
                <div class="field">
                    <label for="gateway">Brama</label>
                    <input id="gateway" name="gateway" type="text" value="{{ old('gateway') }}"
                           placeholder="203.0.113.1" required>
                </div>
                <div class="field">
                    <label for="prefix">Maska dla maszyny</label>
                    <input id="prefix" name="prefix" type="text" value="{{ old('prefix', 24) }}" required>
                    <div class="hint">Zwykle taka sama jak w CIDR. Dostawcy bare-metal czasem wymagają /32.</div>
                </div>
                <div class="field">
                    <label for="range_from">Zakres od <span class="muted">(opcjonalnie)</span></label>
                    <input id="range_from" name="range_from" type="text" value="{{ old('range_from') }}"
                           placeholder="203.0.113.10">
                </div>
                <div class="field">
                    <label for="range_to">Zakres do <span class="muted">(opcjonalnie)</span></label>
                    <input id="range_to" name="range_to" type="text" value="{{ old('range_to') }}"
                           placeholder="203.0.113.200">
                </div>
            </div>

            <div class="field">
                <label for="nameservers">Serwery DNS <span class="muted">(po przecinku)</span></label>
                <input id="nameservers" name="nameservers" type="text"
                       value="{{ old('nameservers', '1.1.1.1, 9.9.9.9') }}">
            </div>

            <button class="btn btn-primary" type="submit">Zaimportuj pulę</button>
            <p class="hint" style="margin-top:10px">
                Zakres warto zawęzić — część adresów z podsieci należy zwykle do infrastruktury
                dostawcy i nie wolno ich przydzielać klientom. Brama jest rezerwowana automatycznie.
            </p>
        </form>
    </details>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Pula</th><th>Węzeł</th><th>Podsieć</th><th>Brama</th>
                    <th>Wszystkie</th><th>Przydzielone</th><th>Zarezerwowane</th><th>Wolne</th></tr>
                </thead>
                <tbody>
                @forelse ($pools as $pool)
                    @php $free = $pool->addresses_count - $pool->assigned_count - $pool->reserved_count; @endphp
                    <tr>
                        <td>{{ $pool->name }}</td>
                        <td class="muted">{{ $pool->hypervisor->name }}</td>
                        <td class="mono">{{ $pool->cidr }}</td>
                        <td class="mono">{{ $pool->gateway }}</td>
                        <td class="num">{{ $pool->addresses_count }}</td>
                        <td class="num">{{ $pool->assigned_count }}</td>
                        <td class="num">{{ $pool->reserved_count }}</td>
                        <td class="num">
                            <span class="pill {{ $free > 5 ? 'ok' : ($free > 0 ? 'warning' : 'critical') }}">
                                {{ $free }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Brak pul. Bez adresów nie da się utworzyć maszyny.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
