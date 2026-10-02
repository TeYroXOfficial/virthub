@extends('layouts.panel')

@section('title', __('Bloki IP'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Bloki IP') }}</h1>
            <p class="lede">{{ __('Pule adresów przydzielanych maszynom — publiczne albo prywatne za NAT-em, IPv4 i IPv6, dla jednego węzła albo całej grupy. Bez wolnego adresu zamówienie zostanie odrzucone.') }}</p>
        </div>
    </div>

    <nav class="tabs" aria-label="{{ __('Wersja IP') }}">
        <a href="{{ route('panel.admin.ip-pools') }}" @if (! $version) aria-current="page" @endif>{{ __('Wszystkie (:count)', ['count' => $totals[4] + $totals[6]]) }}</a>
        <a href="{{ route('panel.admin.ip-pools', ['version' => 4]) }}" @if ($version === 4) aria-current="page" @endif>{{ __('IPv4 (:count)', ['count' => $totals[4]]) }}</a>
        <a href="{{ route('panel.admin.ip-pools', ['version' => 6]) }}" @if ($version === 6) aria-current="page" @endif>{{ __('IPv6 (:count)', ['count' => $totals[6]]) }}</a>
    </nav>

    <details class="form-block card" @if($pools->isEmpty() || $errors->any()) open @endif>
        <summary>{{ __('Dodaj blok IP') }}</summary>
        <form method="POST" action="{{ route('panel.admin.ip-pools.store') }}" style="margin-top:12px" data-pool-form>
            @csrf

            <div class="grid grid-2">
                <div class="field">
                    <label for="n-name">{{ __('Nazwa bloku') }}</label>
                    <input id="n-name" name="name" type="text" value="{{ old('name') }}" placeholder="{{ __('np. WAW publiczne /24') }}" required>
                </div>
                <div class="field">
                    <label for="n-type">{{ __('Rodzaj') }}</label>
                    <select id="n-type" name="type">
                        <option value="public" @selected(old('type', 'public') === 'public')>{{ __('Publiczny — adresy routowane przez dostawcę') }}</option>
                        <option value="nat" @selected(old('type') === 'nat')>{{ __('NAT — adresy prywatne za węzłem') }}</option>
                    </select>
                </div>
                <div class="field">
                    <label for="n-cidr">{{ __('Podsieć (CIDR)') }}</label>
                    <input id="n-cidr" name="cidr" type="text" value="{{ old('cidr') }}" placeholder="{{ __('203.0.113.0/24 albo 2001:db8:10::/64') }}" required>
                    <div class="hint">{{ __('Wersję IP (IPv4 albo IPv6) wykrywamy z podsieci. NAT: sieć prywatna, np. 10.10.0.0/24, albo ULA dla IPv6, np. fd00:10::/64.') }}</div>
                </div>
                <div class="field">
                    <label for="n-gw">{{ __('Brama') }}</label>
                    <input id="n-gw" name="gateway" type="text" value="{{ old('gateway') }}" placeholder="203.0.113.1" required>
                    <div class="hint">{{ __('Przy NAT to adres węzła na mostku NAT (np. 10.10.0.1).') }}</div>
                </div>
                <div class="field">
                    <label for="n-prefix">{{ __('Maska dla maszyny') }}</label>
                    <input id="n-prefix" name="prefix" type="text" value="{{ old('prefix', 24) }}" required>
                    <div class="hint">{{ __('Zwykle taka sama jak w CIDR (IPv6: zwykle 64). Dostawcy bare-metal czasem wymagają /32.') }}</div>
                </div>
            </div>

            <h3 class="card-title" style="margin-top:6px">{{ __('Adresy w bloku') }}</h3>
            <div class="field">
                <label class="check-line"><input type="radio" name="fill" value="subnet" @checked(old('fill', 'subnet') === 'subnet')> {{ __('Cała podsieć — wszystkie adresy poza adresem sieci, rozgłoszeniowym i bramą') }}</label>
                <label class="check-line"><input type="radio" name="fill" value="range" @checked(old('fill') === 'range')> {{ __('Zakres od – do') }}</label>
                <label class="check-line"><input type="radio" name="fill" value="none" @checked(old('fill') === 'none')> {{ __('Pusty blok — adresy dodam potem (pojedynczo, zakresem albo podsiecią)') }}</label>
                <div class="hint">{{ __('Blok IPv6 nie jest rozwijany na adresy — powstają kolejno przy zamówieniach.') }}</div>
            </div>
            <div class="grid grid-2" data-fill="range">
                <div class="field">
                    <label for="n-from">{{ __('Od') }}</label>
                    <input id="n-from" name="range_from" type="text" value="{{ old('range_from') }}" placeholder="203.0.113.10">
                </div>
                <div class="field">
                    <label for="n-to">{{ __('Do') }}</label>
                    <input id="n-to" name="range_to" type="text" value="{{ old('range_to') }}" placeholder="203.0.113.200">
                </div>
            </div>

            <div class="grid grid-2">
                <div>
                    @include('panel.admin.network._scope-fields', ['current' => null, 'idp' => 'n'])
                </div>
                <div>
                    @include('panel.admin.network._dns-fields', ['idp' => 'n', 'preset' => null, 'nameservers' => null])
                </div>
            </div>

            <fieldset data-type="nat" style="border:1px solid var(--border); border-radius:8px; padding:12px 16px; margin:0 0 16px">
                <legend class="muted" style="padding:0 6px">{{ __('Ustawienia NAT') }}</legend>
                <div class="grid grid-3">
                    <div class="field">
                        <label for="n-natpub">{{ __('Adres wyjścia') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
                        <input id="n-natpub" name="nat_public_address" type="text" value="{{ old('nat_public_address') }}" placeholder="198.51.100.7">
                        <div class="hint">{{ __('Puste — adres interfejsu wyjściowego węzła (maskarada).') }}</div>
                    </div>
                    <div class="field">
                        <label for="n-port">{{ __('Pierwszy port') }}</label>
                        <input id="n-port" name="nat_port_start" type="text" value="{{ old('nat_port_start', 10000) }}">
                        <div class="hint">{{ __('Puste — bez przekierowań (tylko ruch wychodzący).') }}</div>
                    </div>
                    <div class="field">
                        <label for="n-ports">{{ __('Portów na maszynę') }}</label>
                        <input id="n-ports" name="nat_ports_per_server" type="text" value="{{ old('nat_ports_per_server', 20) }}">
                    </div>
                </div>
                <p class="hint" style="margin:0">{{ __('Każdy adres dostaje stały blok portów wyliczony z jego pozycji w podsieci: adres .5 przy starcie 10000 i 10 portach ma porty 10050–10059.') }}</p>
            </fieldset>

            <button class="btn btn-primary" type="submit">{{ __('Utwórz blok') }}</button>
        </form>
    </details>

    <div class="card flush">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __('Blok') }}</th><th>{{ __('Rodzaj') }}</th><th>{{ __('Przypisanie') }}</th><th>{{ __('Podsieć') }}</th><th>{{ __('Resolwery') }}</th>
                    <th>{{ __('Przydzielone') }}</th><th>{{ __('Wolne') }}</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($pools as $pool)
                    @php $free = $pool->addresses_count - $pool->assigned_count - $pool->reserved_count; @endphp
                    <tr>
                        <td><a href="{{ route('panel.admin.ip-pools.show', $pool) }}"><strong>{{ $pool->name }}</strong></a>
                            <div class="hint mono">{{ __('brama :gateway', ['gateway' => $pool->gateway]) }}</div></td>
                        <td>
                            <span class="pill neutral">{{ __('IPv:version', ['version' => $pool->version]) }}</span>
                            <span class="pill {{ $pool->isNat() ? 'warning' : 'info' }}">{{ $pool->typeLabel() }}</span>
                            @if ($span = $pool->natPortSpan())
                                <div class="hint mono">{{ __('porty :from–:to', ['from' => $span['from'], 'to' => $span['to']]) }}</div>
                            @endif
                            @foreach ($pool->hostNetworkConflicts() as $c)
                                <div class="hint" style="color:var(--critical)">{{ __('Nachodzi na sieć węzła :node (:interface: :network) — SSH przez porty NAT nie zadziała. Utwórz pulę z inną podsiecią, np. 10.77.0.0/24.', $c) }}</div>
                            @endforeach
                        </td>
                        <td>{{ $pool->isGroupPool() ? __('grupa :name', ['name' => $pool->group?->name]) : ($pool->hypervisor?->name ?? '—') }}</td>
                        <td class="mono">{{ $pool->cidr }}</td>
                        <td class="mono" style="font-size:12.5px">{{ implode(', ', $pool->nameserverList()) }}</td>
                        <td class="num">{{ $pool->assigned_count }}</td>
                        <td class="num">
                            @if ($pool->version === 6)
                                <span class="pill ok" title="{{ __('Adresy IPv6 powstają przy przydziale') }}">{{ __('na żądanie') }}</span>
                            @else
                                <span class="pill {{ $free > 5 ? 'ok' : ($free > 0 ? 'warning' : 'critical') }}">{{ $free }} / {{ $pool->addresses_count }}</span>
                            @endif
                        </td>
                        <td style="text-align:right"><a class="btn btn-sm" href="{{ route('panel.admin.ip-pools.show', $pool) }}">{{ __('Zarządzaj') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted" style="text-align:center; padding:24px">{{ __('Brak bloków. Bez adresów nie da się utworzyć maszyny.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="hint" style="margin-top:16px">{{ __('Grupy węzłów (wspólne pule adresów, lokalizacje) ustawisz w:') }}
        <a href="{{ route('panel.admin.hypervisor-groups') }}">{{ __('Infrastruktura → Grupy hypervisorów') }}</a></p>

    @include('panel.admin.network._form-script')
@endsection
