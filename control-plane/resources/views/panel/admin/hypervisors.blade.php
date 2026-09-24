@extends('layouts.panel')

@section('title', 'Hypervisory')

@section('content')
    <h1>Hypervisory</h1>
    <p class="lede">Węzły, na których stoją maszyny klientów.</p>

    @include('panel.admin._nav')

    <div class="card">
        <h3>Dodaj węzeł</h3>
        <p>
            Podaj tylko nazwę. Adres, liczba rdzeni, pamięć i pojemność dysku zostaną
            wykryte automatycznie, kiedy węzeł zgłosi się po instalacji.
        </p>
        <p class="hint">
            Rodzaj węzła instalator też wykrywa sam: serwer z VT-x/AMD-V dostaje KVM i uruchamia
            maszyny wirtualne, a serwer bez niego (np. VPS) — kontenery LXC. Żeby wymusić kontenery
            na serwerze z KVM, uruchom polecenie z <span class="mono">sudo VH_VIRT=lxc bash</span>
            zamiast <span class="mono">sudo bash</span>.
        </p>
        <form method="POST" action="{{ route('panel.admin.hypervisors.store') }}">
            @csrf
            <div class="field">
                <label for="name">Nazwa węzła</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}"
                       placeholder="node1" required>
                <div class="hint">Krótka nazwa robocza, widoczna tylko dla personelu.</div>
            </div>
            <button class="btn btn-primary" type="submit">Dodaj i wygeneruj polecenie instalacyjne</button>
        </form>
    </div>

    @forelse ($hypervisors as $node)
        <div class="card">
            <h3>
                {{ $node->name }}
                <span class="pill {{ $node->isOnline() ? 'ok' : ($node->enrolled_at ? 'critical' : 'warning') }}"
                      style="float:right">
                    {{ $node->isOnline() ? 'online' : ($node->enrolled_at ? $node->status : 'czeka na instalację') }}
                </span>
            </h3>

            @if ($node->enrolled_at === null)
                {{-- Węzeł dodany, agent jeszcze nie zainstalowany --}}
                <p class="muted">
                    @if ($node->isAwaitingEnrollment())
                        Bilet instalacyjny jest ważny do
                        {{ $node->enrollment_expires_at->format('H:i') }}
                        ({{ $node->enrollment_expires_at->diffForHumans() }}).
                        Jeśli zgubiłeś polecenie, wygeneruj nowe.
                    @else
                        Bilet instalacyjny wygasł. Wygeneruj nowy, żeby dokończyć instalację.
                    @endif
                </p>
                <div class="btn-row">
                    <form method="POST" action="{{ route('panel.admin.hypervisors.enrollment', $node) }}">
                        @csrf
                        <button class="btn btn-primary" type="submit">Wygeneruj polecenie instalacyjne</button>
                    </form>
                    <form method="POST" action="{{ route('panel.admin.hypervisors.destroy', $node) }}"
                          onsubmit="return confirm('Usunąć węzeł {{ $node->name }}?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger" type="submit">Usuń węzeł</button>
                    </form>
                </div>
            @else
                <dl class="kv">
                    <dt>Rodzaj</dt><dd>{{ $node->virtualization->label() }}</dd>
                    <dt>Grupa</dt><dd>{{ $node->group?->name ?? '—' }}</dd>
                    <dt>Nazwa hosta</dt><dd class="mono">{{ $node->hostname }}</dd>
                    <dt>Adres agenta</dt><dd class="mono">{{ $node->agent_url }}</dd>
                    <dt>Zarejestrowany</dt><dd>{{ $node->enrolled_at->format('d.m.Y H:i') }}</dd>
                    <dt>Ostatni kontakt</dt><dd>{{ $node->last_seen_at?->diffForHumans() ?? 'nigdy' }}</dd>
                    <dt>Maszyny</dt><dd class="num">{{ $node->servers_count }}</dd>
                    <dt>Certyfikat</dt>
                    <dd>{{ $node->agent_tls_cert ? 'przypięty (własny węzła)' : 'weryfikacja publicznym urzędem' }}</dd>
                </dl>

                <details class="form-block">
                    <summary>Ustawienia pojemności i trybu pracy</summary>
                    <form method="POST" action="{{ route('panel.admin.hypervisors.update', $node) }}">
                        @csrf @method('PUT')
                        <div class="grid grid-2">
                            <div class="field">
                                <label for="cpu-{{ $node->id }}">Dostępne vCPU</label>
                                <input id="cpu-{{ $node->id }}" name="cpu_cores_total" type="text"
                                       value="{{ $node->cpu_cores_total }}" required>
                                <div class="hint">Zajęte: {{ $node->cpu_cores_used }}</div>
                            </div>
                            <div class="field">
                                <label for="ram-{{ $node->id }}">Dostępna pamięć (MB)</label>
                                <input id="ram-{{ $node->id }}" name="ram_mb_total" type="text"
                                       value="{{ $node->ram_mb_total }}" required>
                                <div class="hint">Zajęte: {{ $node->ram_mb_used }} MB</div>
                            </div>
                            <div class="field">
                                <label for="disk-{{ $node->id }}">Dostępny dysk (GB)</label>
                                <input id="disk-{{ $node->id }}" name="disk_gb_total" type="text"
                                       value="{{ $node->disk_gb_total }}" required>
                                <div class="hint">Zajęte: {{ $node->disk_gb_used }} GB</div>
                            </div>
                            <div class="field">
                                <label for="bridge-{{ $node->id }}">Mostek sieciowy</label>
                                <input id="bridge-{{ $node->id }}" name="bridge" type="text"
                                       value="{{ $node->bridge }}" required>
                                <div class="hint">Interfejs, do którego podpinane są maszyny.</div>
                            </div>
                        </div>
                        <div class="field">
                            <label for="group-{{ $node->id }}">Grupa węzłów</label>
                            <select id="group-{{ $node->id }}" name="hypervisor_group_id">
                                <option value="">— bez grupy —</option>
                                @foreach ($groups as $group)
                                    <option value="{{ $group->id }}" @selected($node->hypervisor_group_id === $group->id)>
                                        {{ $group->name }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="hint">
                                Węzeł korzysta z pul swojej grupy i z pul przypisanych bezpośrednio do niego.
                                Grupy zakłada się w zakładce <a href="{{ route('panel.admin.ip-pools') }}">Adresy IP</a>.
                            </div>
                        </div>
                        <div class="field">
                            <label for="status-{{ $node->id }}">Tryb pracy</label>
                            <select id="status-{{ $node->id }}" name="status">
                                <option value="online" @selected($node->status === 'online')>Online</option>
                                <option value="maintenance" @selected($node->status === 'maintenance')>Konserwacja</option>
                                <option value="offline" @selected($node->status === 'offline')>Offline</option>
                            </select>
                            <div class="hint">
                                Tryb konserwacji nie jest nadpisywany przez automatyczny heartbeat.
                            </div>
                        </div>
                        <div class="field">
                            <label style="font-weight:400; display:flex; gap:8px; align-items:center;">
                                <input type="checkbox" name="accepts_new_servers" value="1"
                                       style="width:auto" @checked($node->accepts_new_servers)>
                                Przyjmuje nowe maszyny
                            </label>
                        </div>
                        <button class="btn btn-primary" type="submit">Zapisz</button>
                    </form>
                </details>

                <div class="btn-row">
                    <form method="POST" action="{{ route('panel.admin.hypervisors.check', $node) }}">
                        @csrf
                        <button class="btn" type="submit">Sprawdź łączność</button>
                    </form>
                    @if ($node->servers_count === 0)
                        <form method="POST" action="{{ route('panel.admin.hypervisors.destroy', $node) }}"
                              onsubmit="return confirm('Usunąć węzeł {{ $node->name }}?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger" type="submit">Usuń węzeł</button>
                        </form>
                    @endif
                </div>

                @if ($node->last_health)
                    <details class="form-block">
                        <summary>Ostatni raport węzła</summary>
                        <dl class="kv">
                            @foreach ($node->last_health as $key => $value)
                                <dt class="mono">{{ $key }}</dt>
                                <dd class="mono">{{ is_bool($value) ? ($value ? 'tak' : 'nie') : $value }}</dd>
                            @endforeach
                        </dl>
                    </details>
                @endif
            @endif
        </div>
    @empty
        <div class="card empty">
            Brak węzłów. Dodaj pierwszy formularzem powyżej — dostaniesz jedno polecenie
            do wklejenia na serwerze.
        </div>
    @endforelse
@endsection
