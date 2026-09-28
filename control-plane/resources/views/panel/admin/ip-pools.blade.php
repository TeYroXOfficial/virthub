@extends('layouts.panel')

@section('title', __('Adresy IP'))

@section('content')
    <h1>{{ __('Pule adresów') }}</h1>
    <p class="lede">
        {{ __('Adresy przydzielane maszynom — publiczne albo prywatne za NAT-em, IPv4 i IPv6, dla pojedynczego węzła albo całej grupy węzłów. Bez wolnego adresu zamówienie zostanie odrzucone.') }}
    </p>

    @include('panel.admin._nav')

    <details class="form-block card" @if($pools->isEmpty() || $errors->hasAny(array_keys(\App\Domain\Network\IpPoolManager::rules()))) open @endif>
        <summary>{{ __('Dodaj pulę') }}</summary>
        <form method="POST" action="{{ route('panel.admin.ip-pools.store') }}" style="margin-top:12px" id="pool-form">
            @csrf

            <div class="grid grid-2">
                <div class="field">
                    <label for="type">{{ __('Rodzaj puli') }}</label>
                    <select id="type" name="type">
                        <option value="public" @selected(old('type', 'public') === 'public')>{{ __('Publiczna — adresy routowane przez dostawcę') }}</option>
                        <option value="nat" @selected(old('type') === 'nat')>{{ __('NAT — adresy prywatne za węzłem') }}</option>
                    </select>
                    <div class="hint">
                        {{ __('Maszyna z adresem NAT wychodzi w świat adresem węzła, a z zewnątrz jest osiągalna przez przekierowane porty.') }}
                    </div>
                </div>
                <div class="field">
                    <label for="scope">{{ __('Zasięg') }}</label>
                    <select id="scope" name="scope">
                        <option value="hypervisor" @selected(old('scope', 'hypervisor') === 'hypervisor')>{{ __('Jeden węzeł') }}</option>
                        <option value="group" @selected(old('scope') === 'group') @disabled($groups->isEmpty())>
                            {{ __('Grupa węzłów:niej', ['niej' => $groups->isEmpty() ? __(' (najpierw utwórz grupę niżej)') : '']) }}
                        </option>
                    </select>
                    <div class="hint">{{ __('Pula grupy obsługuje wszystkie jej węzły — podsieć musi docierać do każdego z nich.') }}</div>
                </div>
                <div class="field" data-scope="hypervisor">
                    <label for="hypervisor_id">{{ __('Węzeł') }}</label>
                    <select id="hypervisor_id" name="hypervisor_id">
                        @forelse ($hypervisors as $node)
                            <option value="{{ $node->id }}" @selected((int) old('hypervisor_id') === $node->id)>
                                {{ $node->name }}{{ $node->group ? ' ('.$node->group->name.')' : '' }}
                            </option>
                        @empty
                            <option value="" disabled>{{ __('Najpierw dodaj hypervisor') }}</option>
                        @endforelse
                    </select>
                </div>
                <div class="field" data-scope="group">
                    <label for="hypervisor_group_id">{{ __('Grupa węzłów') }}</label>
                    <select id="hypervisor_group_id" name="hypervisor_group_id">
                        <option value="">—</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}" @selected((int) old('hypervisor_group_id') === $group->id)>
                                {{ __(':name (:count węzł.)', ['name' => $group->name, 'count' => $group->hypervisors->count()]) }}
                            </option>
                        @endforeach
                    </select>
                    <div class="hint">{{ __('Używana tylko przy zasięgu „Grupa węzłów".') }}</div>
                </div>
            </div>

            <div class="grid grid-2">
                <div class="field">
                    <label for="name">{{ __('Nazwa puli') }}</label>
                    <input id="name" name="name" type="text" value="{{ old('name') }}"
                           placeholder="{{ __('Pula podstawowa') }}" required>
                </div>
                <div class="field">
                    <label for="cidr">{{ __('Podsieć (CIDR)') }}</label>
                    <input id="cidr" name="cidr" type="text" value="{{ old('cidr') }}"
                           placeholder="{{ __('203.0.113.0/24 albo 2001:db8:10::/64') }}" required>
                    <div class="hint">
                        {{ __('Wersję IP wykrywamy z podsieci. Pula NAT: sieć prywatna (np. 10.10.0.0/24) albo ULA dla IPv6 (np. fd00:10::/64).') }}
                    </div>
                </div>
                <div class="field">
                    <label for="gateway">{{ __('Brama') }}</label>
                    <input id="gateway" name="gateway" type="text" value="{{ old('gateway') }}"
                           placeholder="203.0.113.1" required>
                    <div class="hint">{{ __('Przy NAT to adres, który węzeł dostanie na mostku NAT (np. 10.10.0.1).') }}</div>
                </div>
                <div class="field">
                    <label for="prefix">{{ __('Maska dla maszyny') }}</label>
                    <input id="prefix" name="prefix" type="text" value="{{ old('prefix', 24) }}" required>
                    <div class="hint">{{ __('Zwykle taka sama jak w CIDR (IPv6: zwykle 64). Dostawcy bare-metal czasem wymagają /32.') }}</div>
                </div>
                <div class="field">
                    <label for="range_from">{{ __('Zakres od') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
                    <input id="range_from" name="range_from" type="text" value="{{ old('range_from') }}"
                           placeholder="203.0.113.10">
                </div>
                <div class="field">
                    <label for="range_to">{{ __('Zakres do') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
                    <input id="range_to" name="range_to" type="text" value="{{ old('range_to') }}"
                           placeholder="203.0.113.200">
                </div>
            </div>

            <div class="field">
                <label for="nameservers">{{ __('Serwery DNS') }} <span class="muted">{{ __('(po przecinku)') }}</span></label>
                <input id="nameservers" name="nameservers" type="text"
                       value="{{ old('nameservers', '1.1.1.1, 9.9.9.9') }}">
                <div class="hint">{{ __('Dla puli IPv6 zostaw puste, żeby użyć domyślnych serwerów IPv6.') }}</div>
            </div>

            <fieldset data-type="nat" style="border:1px solid var(--border); border-radius:8px; padding:12px 16px; margin:0 0 16px">
                <legend class="muted" style="padding:0 6px">{{ __('Ustawienia NAT') }}</legend>
                <div class="grid grid-3">
                    <div class="field">
                        <label for="nat_public_address">{{ __('Adres wyjścia') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
                        <input id="nat_public_address" name="nat_public_address" type="text"
                               value="{{ old('nat_public_address') }}" placeholder="198.51.100.7">
                        <div class="hint">{{ __('Puste — adres interfejsu wyjściowego węzła (maskarada).') }}</div>
                    </div>
                    <div class="field">
                        <label for="nat_port_start">{{ __('Pierwszy port') }}</label>
                        <input id="nat_port_start" name="nat_port_start" type="text"
                               value="{{ old('nat_port_start', 10000) }}">
                        <div class="hint">{{ __('Puste — bez przekierowań (tylko ruch wychodzący).') }}</div>
                    </div>
                    <div class="field">
                        <label for="nat_ports_per_server">{{ __('Portów na maszynę') }}</label>
                        <input id="nat_ports_per_server" name="nat_ports_per_server" type="text"
                               value="{{ old('nat_ports_per_server', 20) }}">
                    </div>
                </div>
                <p class="hint" style="margin:0">
                    {{ __('Każdy adres dostaje stały blok portów wyliczony z jego pozycji w podsieci: adres .5 przy starcie 10000 i 10 portach ma porty 10050–10059. Pierwsze porty bloku są na stałe przypisane do dostępu wg systemu: Linux — SSH/SFTP (22), Windows — RDP (3389) i SSH/SFTP (22). Pozostałe klient kieruje na dowolne porty w maszynie, nieustawione przechodzą 1:1. Porty dotyczą IPv4; pula NAT IPv6 daje tylko ruch wychodzący.') }}
                </p>
            </fieldset>

            <button class="btn btn-primary" type="submit">{{ __('Dodaj pulę') }}</button>
            <p class="hint" style="margin-top:10px">
                {{ __('Pula IPv4 jest rozwijana na pojedyncze adresy (brama jest rezerwowana automatycznie). Zakres warto zawęzić — część adresów z podsieci należy zwykle do infrastruktury dostawcy. Pula IPv6 nie jest rozwijana: adresy powstają kolejno od początku zakresu przy zamówieniach.') }}
            </p>
        </form>
    </details>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __('Pula') }}</th><th>{{ __('Rodzaj') }}</th><th>{{ __('Zasięg') }}</th><th>{{ __('Podsieć') }}</th><th>{{ __('Brama') }}</th>
                    <th>{{ __('Przydzielone') }}</th><th>{{ __('Wolne') }}</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($pools as $pool)
                    @php $free = $pool->addresses_count - $pool->assigned_count - $pool->reserved_count; @endphp
                    <tr>
                        <td>
                            {{ $pool->name }}
                            @if ($pool->range_from || $pool->range_to)
                                <div class="hint mono">{{ $pool->firstAssignable() }} – {{ $pool->lastAssignable() }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="pill {{ $pool->isNat() ? 'warning' : 'neutral' }}">{{ $pool->typeLabel() }}</span>
                            <span class="pill neutral">{{ __('IPv:version', ['version' => $pool->version]) }}</span>
                            @if ($span = $pool->natPortSpan())
                                <div class="hint mono">{{ __('porty :from–:to (:nat_ports_per_server/maszynę)', ['from' => $span['from'], 'to' => $span['to'], 'nat_ports_per_server' => $pool->nat_ports_per_server]) }}</div>
                            @endif
                            @if ($pool->nat_public_address)
                                <div class="hint mono">{{ __('wyjście :nat_public_address', ['nat_public_address' => $pool->nat_public_address]) }}</div>
                            @endif
                            @foreach ($pool->hostNetworkConflicts() as $c)
                                <div class="hint" style="color:var(--critical)">{{ __('Nachodzi na sieć węzła :node (:interface: :network) — SSH przez porty NAT nie zadziała. Utwórz pulę z inną podsiecią, np. 10.77.0.0/24.', $c) }}</div>
                            @endforeach
                        </td>
                        <td class="muted">{{ $pool->scopeLabel() }}</td>
                        <td class="mono">{{ $pool->cidr }}</td>
                        <td class="mono">{{ $pool->gateway }}</td>
                        <td class="num">{{ $pool->assigned_count }}</td>
                        <td class="num">
                            @if ($pool->version === 6)
                                <span class="pill ok" title="{{ __('Adresy IPv6 powstają przy przydziale') }}">{{ __('przydział na żądanie') }}</span>
                            @else
                                <span class="pill {{ $free > 5 ? 'ok' : ($free > 0 ? 'warning' : 'critical') }}">
                                    {{ $free }} / {{ $pool->addresses_count }}
                                </span>
                            @endif
                        </td>
                        <td>
                            @if ($pool->assigned_count === 0)
                                <form method="POST" action="{{ route('panel.admin.ip-pools.destroy', $pool) }}"
                                      data-confirm="{{ __('Usunąć pulę :name?', ['name' => $pool->name]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger" type="submit">{{ __('Usuń') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">{{ __('Brak pul. Bez adresów nie da się utworzyć maszyny.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('panel.admin._groups')

    <script>
        // Pokazuje tylko pola pasujące do wybranego zasięgu i rodzaju puli.
        // Bez JavaScriptu formularz działa tak samo — widać po prostu wszystkie pola.
        (function () {
            const form = document.getElementById('pool-form');
            if (!form) return;
            const sync = () => {
                const scope = form.querySelector('#scope').value;
                const type = form.querySelector('#type').value;
                form.querySelectorAll('[data-scope]').forEach(el => el.hidden = el.dataset.scope !== scope);
                form.querySelectorAll('[data-type]').forEach(el => el.hidden = el.dataset.type !== type);
            };
            form.querySelector('#scope').addEventListener('change', sync);
            form.querySelector('#type').addEventListener('change', sync);
            sync();
        })();
    </script>
@endsection
