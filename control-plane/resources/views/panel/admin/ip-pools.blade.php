@extends('layouts.panel')

@section('title', 'Adresy IP')

@section('content')
    <h1>Pule adresów</h1>
    <p class="lede">
        Adresy przydzielane maszynom — publiczne albo prywatne za NAT-em, IPv4 i IPv6,
        dla pojedynczego węzła albo całej grupy węzłów. Bez wolnego adresu zamówienie zostanie odrzucone.
    </p>

    @include('panel.admin._nav')

    <details class="form-block card" @if($pools->isEmpty() || $errors->hasAny(array_keys(\App\Domain\Network\IpPoolManager::rules()))) open @endif>
        <summary>Dodaj pulę</summary>
        <form method="POST" action="{{ route('panel.admin.ip-pools.store') }}" style="margin-top:12px" id="pool-form">
            @csrf

            <div class="grid grid-2">
                <div class="field">
                    <label for="type">Rodzaj puli</label>
                    <select id="type" name="type">
                        <option value="public" @selected(old('type', 'public') === 'public')>Publiczna — adresy routowane przez dostawcę</option>
                        <option value="nat" @selected(old('type') === 'nat')>NAT — adresy prywatne za węzłem</option>
                    </select>
                    <div class="hint">
                        Maszyna z adresem NAT wychodzi w świat adresem węzła, a z zewnątrz jest osiągalna
                        przez przekierowane porty.
                    </div>
                </div>
                <div class="field">
                    <label for="scope">Zasięg</label>
                    <select id="scope" name="scope">
                        <option value="hypervisor" @selected(old('scope', 'hypervisor') === 'hypervisor')>Jeden węzeł</option>
                        <option value="group" @selected(old('scope') === 'group') @disabled($groups->isEmpty())>
                            Grupa węzłów{{ $groups->isEmpty() ? ' (najpierw utwórz grupę niżej)' : '' }}
                        </option>
                    </select>
                    <div class="hint">Pula grupy obsługuje wszystkie jej węzły — podsieć musi docierać do każdego z nich.</div>
                </div>
                <div class="field" data-scope="hypervisor">
                    <label for="hypervisor_id">Węzeł</label>
                    <select id="hypervisor_id" name="hypervisor_id">
                        @forelse ($hypervisors as $node)
                            <option value="{{ $node->id }}" @selected((int) old('hypervisor_id') === $node->id)>
                                {{ $node->name }}{{ $node->group ? ' ('.$node->group->name.')' : '' }}
                            </option>
                        @empty
                            <option value="" disabled>Najpierw dodaj hypervisor</option>
                        @endforelse
                    </select>
                </div>
                <div class="field" data-scope="group">
                    <label for="hypervisor_group_id">Grupa węzłów</label>
                    <select id="hypervisor_group_id" name="hypervisor_group_id">
                        <option value="">—</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}" @selected((int) old('hypervisor_group_id') === $group->id)>
                                {{ $group->name }} ({{ $group->hypervisors->count() }} węzł.)
                            </option>
                        @endforeach
                    </select>
                    <div class="hint">Używana tylko przy zasięgu „Grupa węzłów".</div>
                </div>
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
                           placeholder="203.0.113.0/24 albo 2001:db8:10::/64" required>
                    <div class="hint">
                        Wersję IP wykrywamy z podsieci. Pula NAT: sieć prywatna (np. 10.10.0.0/24)
                        albo ULA dla IPv6 (np. fd00:10::/64).
                    </div>
                </div>
                <div class="field">
                    <label for="gateway">Brama</label>
                    <input id="gateway" name="gateway" type="text" value="{{ old('gateway') }}"
                           placeholder="203.0.113.1" required>
                    <div class="hint">Przy NAT to adres, który węzeł dostanie na mostku NAT (np. 10.10.0.1).</div>
                </div>
                <div class="field">
                    <label for="prefix">Maska dla maszyny</label>
                    <input id="prefix" name="prefix" type="text" value="{{ old('prefix', 24) }}" required>
                    <div class="hint">Zwykle taka sama jak w CIDR (IPv6: zwykle 64). Dostawcy bare-metal czasem wymagają /32.</div>
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
                <div class="hint">Dla puli IPv6 zostaw puste, żeby użyć domyślnych serwerów IPv6.</div>
            </div>

            <fieldset data-type="nat" style="border:1px solid var(--border); border-radius:8px; padding:12px 16px; margin:0 0 16px">
                <legend class="muted" style="padding:0 6px">Ustawienia NAT</legend>
                <div class="grid grid-3">
                    <div class="field">
                        <label for="nat_public_address">Adres wyjścia <span class="muted">(opcjonalnie)</span></label>
                        <input id="nat_public_address" name="nat_public_address" type="text"
                               value="{{ old('nat_public_address') }}" placeholder="198.51.100.7">
                        <div class="hint">Puste — adres interfejsu wyjściowego węzła (maskarada).</div>
                    </div>
                    <div class="field">
                        <label for="nat_port_start">Pierwszy port</label>
                        <input id="nat_port_start" name="nat_port_start" type="text"
                               value="{{ old('nat_port_start', 10000) }}">
                        <div class="hint">Puste — bez przekierowań (tylko ruch wychodzący).</div>
                    </div>
                    <div class="field">
                        <label for="nat_ports_per_server">Portów na maszynę</label>
                        <input id="nat_ports_per_server" name="nat_ports_per_server" type="text"
                               value="{{ old('nat_ports_per_server', 20) }}">
                    </div>
                </div>
                <p class="hint" style="margin:0">
                    Każdy adres dostaje stały blok portów wyliczony z jego pozycji w podsieci:
                    adres .5 przy starcie 10000 i 20 portach ma porty 10100–10119. Pierwszy port bloku
                    prowadzi na SSH (22), pozostałe przechodzą 1:1. Porty dotyczą IPv4; pula NAT IPv6
                    daje tylko ruch wychodzący.
                </p>
            </fieldset>

            <button class="btn btn-primary" type="submit">Dodaj pulę</button>
            <p class="hint" style="margin-top:10px">
                Pula IPv4 jest rozwijana na pojedyncze adresy (brama jest rezerwowana automatycznie).
                Zakres warto zawęzić — część adresów z podsieci należy zwykle do infrastruktury
                dostawcy. Pula IPv6 nie jest rozwijana: adresy powstają kolejno od początku zakresu
                przy zamówieniach.
            </p>
        </form>
    </details>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Pula</th><th>Rodzaj</th><th>Zasięg</th><th>Podsieć</th><th>Brama</th>
                    <th>Przydzielone</th><th>Wolne</th><th></th></tr>
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
                            <span class="pill neutral">IPv{{ $pool->version }}</span>
                            @if ($span = $pool->natPortSpan())
                                <div class="hint mono">porty {{ $span['from'] }}–{{ $span['to'] }}
                                    ({{ $pool->nat_ports_per_server }}/maszynę)</div>
                            @endif
                            @if ($pool->nat_public_address)
                                <div class="hint mono">wyjście {{ $pool->nat_public_address }}</div>
                            @endif
                        </td>
                        <td class="muted">{{ $pool->scopeLabel() }}</td>
                        <td class="mono">{{ $pool->cidr }}</td>
                        <td class="mono">{{ $pool->gateway }}</td>
                        <td class="num">{{ $pool->assigned_count }}</td>
                        <td class="num">
                            @if ($pool->version === 6)
                                <span class="pill ok" title="Adresy IPv6 powstają przy przydziale">przydział na żądanie</span>
                            @else
                                <span class="pill {{ $free > 5 ? 'ok' : ($free > 0 ? 'warning' : 'critical') }}">
                                    {{ $free }} / {{ $pool->addresses_count }}
                                </span>
                            @endif
                        </td>
                        <td>
                            @if ($pool->assigned_count === 0)
                                <form method="POST" action="{{ route('panel.admin.ip-pools.destroy', $pool) }}"
                                      onsubmit="return confirm('Usunąć pulę {{ $pool->name }}?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger" type="submit">Usuń</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Brak pul. Bez adresów nie da się utworzyć maszyny.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <h2 id="groups">Grupy węzłów</h2>
    <p class="lede">
        Węzły w jednej grupie korzystają ze wspólnych pul. Typowo to węzły w tej samej
        lokalizacji, podpięte do tego samego segmentu sieci. Pule przypisane bezpośrednio
        do węzła mają pierwszeństwo przed pulami grupy.
    </p>

    <details class="form-block card" @if($groups->isEmpty()) open @endif>
        <summary>Utwórz grupę</summary>
        <form method="POST" action="{{ route('panel.admin.hypervisor-groups.store') }}" style="margin-top:12px">
            @csrf
            <div class="grid grid-2">
                <div class="field">
                    <label for="group-name">Nazwa</label>
                    <input id="group-name" name="name" type="text" placeholder="Warszawa DC1" required>
                </div>
                <div class="field">
                    <label for="group-description">Opis <span class="muted">(opcjonalnie)</span></label>
                    <input id="group-description" name="description" type="text" placeholder="VLAN 120, wspólna /24">
                </div>
            </div>
            @include('panel.admin._group-members', ['group' => null])
            <button class="btn btn-primary" type="submit">Utwórz grupę</button>
        </form>
    </details>

    @foreach ($groups as $group)
        <div class="card">
            <h3>
                {{ $group->name }}
                <span class="pill neutral" style="float:right">
                    {{ $group->hypervisors->count() }} węzł. · {{ $group->ip_pools_count }} pul
                </span>
            </h3>
            @if ($group->description)
                <p class="muted">{{ $group->description }}</p>
            @endif

            <details class="form-block">
                <summary>Edytuj grupę</summary>
                <form method="POST" action="{{ route('panel.admin.hypervisor-groups.update', $group) }}">
                    @csrf @method('PUT')
                    <div class="grid grid-2">
                        <div class="field">
                            <label for="group-name-{{ $group->id }}">Nazwa</label>
                            <input id="group-name-{{ $group->id }}" name="name" type="text" value="{{ $group->name }}" required>
                        </div>
                        <div class="field">
                            <label for="group-description-{{ $group->id }}">Opis</label>
                            <input id="group-description-{{ $group->id }}" name="description" type="text"
                                   value="{{ $group->description }}">
                        </div>
                    </div>
                    @include('panel.admin._group-members', ['group' => $group])
                    <button class="btn btn-primary" type="submit">Zapisz</button>
                </form>
            </details>

            @if ($group->ip_pools_count === 0)
                <form method="POST" action="{{ route('panel.admin.hypervisor-groups.destroy', $group) }}"
                      onsubmit="return confirm('Usunąć grupę {{ $group->name }}?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger" type="submit">Usuń grupę</button>
                </form>
            @endif
        </div>
    @endforeach

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
