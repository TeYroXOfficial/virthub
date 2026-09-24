@php
    $firewall = app(\App\Domain\Network\Firewall::class);
    $user = auth()->user();
    $editable = $firewall->canManage($user, $server);
    $rules = $server->firewallRules;
    $adminRules = $rules->where('managed_by', 'admin')->values();
    $customerRules = $rules->where('managed_by', '!=', 'admin')->values();
    $protocolLabel = ['tcp' => 'TCP', 'udp' => 'UDP', 'icmp' => 'ICMP', 'any' => 'wszystkie'];
@endphp

<section id="firewall">
    <div class="section-head">
        <h2>Zapora sieciowa</h2>
        <div class="meta-line">
            @if ($server->firewall_enabled)
                <span class="pill ok">włączona</span>
                <span>przychodzące: <strong>{{ $server->firewall_inbound === 'drop' ? 'blokuj poza regułami' : 'przepuszczaj' }}</strong></span>
                <span class="sep">·</span>
                <span>wychodzące: <strong>{{ $server->firewall_outbound === 'drop' ? 'blokuj poza regułami' : 'przepuszczaj' }}</strong></span>
            @else
                <span class="pill neutral">wyłączona</span>
            @endif
            @if ($server->firewall_locked)
                <span class="pill warning">zablokowana przez administratora</span>
            @endif
        </div>
    </div>

    @if ($server->firewall_locked && ! $user->isStaff())
        <div class="alert alert-warning">
            Administrator zablokował zmiany zapory tej maszyny. Reguły działają, ale nie możesz ich
            zmieniać — skontaktuj się z obsługą.
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title"><x-icon name="shield" :size="16"/> Ustawienia</h3>
            <form method="POST" action="{{ route('panel.servers.firewall.policy', $server) }}">
                @csrf
                <div class="field">
                    <label style="font-weight:600; display:flex; gap:8px; align-items:center;">
                        <input type="checkbox" name="enabled" value="1" style="width:auto"
                               @checked($server->firewall_enabled) @disabled(! $editable)>
                        Zapora włączona
                    </label>
                    <div class="hint">Wyłączona zapora nie stosuje reguł — działa tylko ochrona przed podszywaniem się pod cudze adresy.</div>
                </div>
                <div class="grid grid-2" style="gap:12px">
                    <div class="field">
                        <label for="fw-inbound">Ruch przychodzący</label>
                        <select id="fw-inbound" name="inbound" @disabled(! $editable)>
                            <option value="accept" @selected($server->firewall_inbound !== 'drop')>Przepuszczaj (blokuj regułami)</option>
                            <option value="drop" @selected($server->firewall_inbound === 'drop')>Blokuj (zezwalaj regułami)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="fw-outbound">Ruch wychodzący</label>
                        <select id="fw-outbound" name="outbound" @disabled(! $editable)>
                            <option value="accept" @selected($server->firewall_outbound !== 'drop')>Przepuszczaj (blokuj regułami)</option>
                            <option value="drop" @selected($server->firewall_outbound === 'drop')>Blokuj (zezwalaj regułami)</option>
                        </select>
                    </div>
                </div>
                @if ($user->isStaff())
                    <div class="field">
                        <label style="font-weight:400; display:flex; gap:8px; align-items:center;">
                            <input type="checkbox" name="locked" value="1" style="width:auto" @checked($server->firewall_locked)>
                            Zablokuj klientowi zmiany zapory
                        </label>
                    </div>
                @endif
                @if ($editable)
                    <button class="btn btn-primary" type="submit">Zapisz ustawienia</button>
                @endif
                <p class="hint" style="margin-top:10px">
                    Przy blokowaniu odpowiedzi na połączenia nawiązane przez maszynę przechodzą zawsze.
                    Zanim zablokujesz ruch przychodzący, zezwól na SSH/RDP — inaczej odetniesz sobie dostęp
                    (konsola w panelu działa niezależnie od zapory).
                </p>
            </form>
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="plus" :size="16"/> Dodaj regułę</h3>
            @if ($editable)
                <div class="btn-row" style="margin-bottom:14px">
                    @foreach (\App\Domain\Network\Firewall::PRESETS as $key => $preset)
                        <form method="POST" action="{{ route('panel.servers.firewall.preset', [$server, $key]) }}" style="margin:0">
                            @csrf
                            <button class="btn btn-sm" type="submit" title="Zezwól na ruch przychodzący">+ {{ $preset['label'] }}</button>
                        </form>
                    @endforeach
                </div>

                <form method="POST" action="{{ route('panel.servers.firewall.store', $server) }}" id="fw-form">
                    @csrf
                    <div class="grid-compact">
                        <div class="field">
                            <label for="fw-action">Akcja</label>
                            <select id="fw-action" name="action">
                                <option value="accept" @selected(old('action') !== 'drop')>Zezwól</option>
                                <option value="drop" @selected(old('action') === 'drop')>Zablokuj</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="fw-direction">Kierunek</label>
                            <select id="fw-direction" name="direction">
                                <option value="in" @selected(old('direction') !== 'out')>Przychodzący</option>
                                <option value="out" @selected(old('direction') === 'out')>Wychodzący</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="fw-protocol">Protokół</label>
                            <select id="fw-protocol" name="protocol">
                                @foreach ($protocolLabel as $value => $label)
                                    <option value="{{ $value }}" @selected(old('protocol', 'tcp') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" data-ports>
                            <label for="fw-port-from">Port</label>
                            <input id="fw-port-from" name="port_from" type="text" inputmode="numeric" value="{{ old('port_from') }}" placeholder="22">
                        </div>
                        <div class="field" data-ports>
                            <label for="fw-port-to">do portu <span class="muted">(zakres)</span></label>
                            <input id="fw-port-to" name="port_to" type="text" inputmode="numeric" value="{{ old('port_to') }}" placeholder="opcjonalnie">
                        </div>
                        <div class="field">
                            <label for="fw-source" data-peer-label>Adres źródłowy</label>
                            <input id="fw-source" name="source" type="text" value="{{ old('source') }}" placeholder="dowolny">
                        </div>
                    </div>
                    <div class="field">
                        <label for="fw-comment">Opis <span class="muted">(opcjonalnie)</span></label>
                        <input id="fw-comment" name="comment" type="text" maxlength="120" value="{{ old('comment') }}" placeholder="np. panel administracyjny">
                    </div>
                    @if ($user->isStaff())
                        <div class="field">
                            <label style="font-weight:400; display:flex; gap:8px; align-items:center;">
                                <input type="checkbox" name="admin_rule" value="1" style="width:auto">
                                Reguła administratora — sprawdzana przed regułami klienta, klient nie może jej zmienić
                            </label>
                        </div>
                    @endif
                    <button class="btn btn-primary" type="submit">Dodaj regułę</button>
                    <p class="hint" style="margin-top:10px">
                        Adres: pojedynczy IP (198.51.100.7) albo podsieć (10.0.0.0/8, 2001:db8::/32). Puste = dowolny.
                        Porty dotyczą TCP i UDP; bez portu reguła obejmuje wszystkie.
                    </p>
                </form>
            @else
                <p class="muted">Zapora jest zablokowana — nie można dodawać reguł.</p>
            @endif
        </div>
    </div>

    <div class="card" style="padding:0; margin-top:16px">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th style="width:40px">#</th><th>Reguła</th><th>Protokół / porty</th><th>Adres</th><th>Opis</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($adminRules->concat($customerRules) as $rule)
                    @php
                        $mine = ! $rule->isAdminRule() || $user->isStaff();
                        $group = $rule->isAdminRule() ? $adminRules : $customerRules;
                        $index = $group->search(fn ($r) => $r->id === $rule->id);
                    @endphp
                    <tr @if (! $rule->enabled) style="opacity:.55" @endif>
                        <td class="mono">{{ $loop->iteration }}</td>
                        <td>
                            <span class="pill {{ $rule->action === 'accept' ? 'ok' : 'critical' }}">{{ $rule->action === 'accept' ? 'zezwól' : 'blokuj' }}</span>
                            <span class="muted">{{ $rule->direction === 'in' ? 'przychodzący' : 'wychodzący' }}</span>
                            @if ($rule->isAdminRule()) <span class="pill info plain">administrator</span> @endif
                            @unless ($rule->enabled) <span class="pill neutral plain">wyłączona</span> @endunless
                        </td>
                        <td class="mono">
                            {{ $protocolLabel[$rule->protocol] ?? $rule->protocol }}@if ($rule->port_from) · {{ $rule->port_from }}@if ($rule->port_to)–{{ $rule->port_to }}@endif @endif
                        </td>
                        <td class="mono">{{ $rule->source ?? 'dowolny' }}</td>
                        <td class="muted">{{ $rule->comment }}</td>
                        <td style="text-align:right; white-space:nowrap">
                            @if ($editable && $mine)
                                <div class="btn-group">
                                    <form method="POST" action="{{ route('panel.servers.firewall.move', [$server, $rule, 'up']) }}" style="margin:0">
                                        @csrf
                                        <button class="btn btn-sm" type="submit" title="Wyżej" @disabled($index === 0)>↑</button>
                                    </form>
                                    <form method="POST" action="{{ route('panel.servers.firewall.move', [$server, $rule, 'down']) }}" style="margin:0">
                                        @csrf
                                        <button class="btn btn-sm" type="submit" title="Niżej" @disabled($index === $group->count() - 1)>↓</button>
                                    </form>
                                    <form method="POST" action="{{ route('panel.servers.firewall.toggle', [$server, $rule]) }}" style="margin:0">
                                        @csrf
                                        <button class="btn btn-sm" type="submit">{{ $rule->enabled ? 'Wyłącz' : 'Włącz' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('panel.servers.firewall.destroy', [$server, $rule]) }}" style="margin:0"
                                          onsubmit="return confirm('Usunąć regułę?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit">Usuń</button>
                                    </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">
                        Brak reguł.
                        {{ $server->firewall_enabled && $server->firewall_inbound === 'drop' ? 'Cały nowy ruch przychodzący jest blokowany.' : 'Ruch przechodzi bez ograniczeń.' }}
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="hint">
        Reguły są sprawdzane od góry — pierwsza pasująca decyduje. Reguły administratora zawsze przed regułami klienta.
        Niezależnie od zapory ruch wychodzący z adresu spoza puli tej maszyny jest zawsze blokowany.
    </p>
</section>

@push('scripts')
    <script>
        // Porty tylko dla TCP/UDP; etykieta adresu zależnie od kierunku.
        (function () {
            const form = document.getElementById('fw-form');
            if (!form) return;
            const sync = () => {
                const proto = form.querySelector('#fw-protocol').value;
                form.querySelectorAll('[data-ports]').forEach(el => el.hidden = !['tcp', 'udp'].includes(proto));
                form.querySelector('[data-peer-label]').textContent =
                    form.querySelector('#fw-direction').value === 'in' ? 'Adres źródłowy' : 'Adres docelowy';
            };
            form.addEventListener('change', sync);
            sync();
        })();
    </script>
@endpush
