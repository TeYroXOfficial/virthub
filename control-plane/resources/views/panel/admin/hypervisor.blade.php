@extends('layouts.panel')

@section('title', $node->name)

@php
    $pct = fn ($used, $total) => $total > 0 ? min(100, (int) round($used / $total * 100)) : 0;
    $isAdmin = auth()->user()->isAdmin();
@endphp

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.hypervisors') }}" style="font-size:13px">← Hypervisory</a>
            <h1>{{ $node->name }}</h1>
            <div class="meta-line">
                @if (! $node->enrolled_at)
                    <span class="pill warning">czeka na instalację</span>
                @elseif ($node->status === 'maintenance')
                    <span class="pill warning">konserwacja</span>
                @else
                    <span class="pill {{ $node->isOnline() ? 'ok' : 'critical' }}">{{ $node->isOnline() ? 'online' : 'offline' }}</span>
                @endif
                @if ($node->enrolled_at)
                    <span>{{ $node->virtualization->label() }}</span>
                    <span class="sep">·</span> <span class="mono">{{ $node->hostname }}</span>
                @endif
                @if ($node->group)
                    <span class="sep">·</span> <span>grupa {{ $node->group->name }}</span>
                @endif
            </div>
        </div>
        <div class="actions">
            @if ($node->enrolled_at)
                <form method="POST" action="{{ route('panel.admin.hypervisors.check', $node) }}" style="margin:0">
                    @csrf
                    <button class="btn" type="submit"><x-icon name="refresh" :size="15"/> Sprawdź łączność</button>
                </form>
            @else
                <form method="POST" action="{{ route('panel.admin.hypervisors.enrollment', $node) }}" style="margin:0">
                    @csrf
                    <button class="btn btn-primary" type="submit">Wygeneruj polecenie instalacyjne</button>
                </form>
            @endif
        </div>
    </div>

    @include('panel.admin._nav')

    <div class="grid grid-4" style="margin-bottom:16px">
        @foreach ([['Procesor', $node->cpu_cores_used, $node->cpu_cores_total, 'vCPU'], ['Pamięć', $node->ram_mb_used, $node->ram_mb_total, 'MB'], ['Dysk', $node->disk_gb_used, $node->disk_gb_total, 'GB']] as [$label, $u, $t, $unit])
            <div class="stat">
                <div class="stat-label">{{ $label }}</div>
                <div class="stat-value">{{ $pct($u, $t) }}%</div>
                <div class="stat-sub">{{ $u }} / {{ $t }} {{ $unit }}</div>
                <div class="meter {{ $pct($u, $t) > 85 ? 'hot' : '' }}"><i style="width: {{ $pct($u, $t) }}%"></i></div>
            </div>
        @endforeach
        <div class="stat">
            <div class="stat-label">Maszyny</div>
            <div class="stat-value">{{ $node->servers_count }}@if ($node->max_servers !== null)<span class="muted" style="font-size:15px"> / {{ $node->max_servers }}</span>@endif</div>
            <div class="stat-sub">ostatni kontakt: {{ $node->last_seen_at?->diffForHumans() ?? 'nigdy' }}</div>
        </div>
    </div>

    <nav class="tabs" role="tablist">
        <button type="button" role="tab" data-tab="servers">Maszyny ({{ $servers->count() }})</button>
        <button type="button" role="tab" data-tab="settings">Ustawienia</button>
        <button type="button" role="tab" data-tab="info">Informacje</button>
    </nav>

    <div data-tab-panel="servers" role="tabpanel">
        @if ($servers->isEmpty())
            <div class="card empty">Na tym węźle nie ma maszyn.</div>
        @else
            <form method="POST" action="{{ route('panel.admin.servers.bulk') }}" id="servers-bulk"
                  onsubmit="return event.submitter?.dataset.confirm ? confirm(event.submitter.dataset.confirm) : confirm('Wykonać operację na zaznaczonych maszynach?')">
                @csrf
                <div class="bulk-bar">
                    <span>Zaznaczone:</span>
                    <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete">Usuń</button>
                    @if ($isAdmin)
                        <button class="btn btn-sm" type="submit" name="action" value="purge">Usuń tylko z panelu</button>
                    @endif
                </div>
                <div class="card" style="padding:0">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th style="width:32px"></th><th>Maszyna</th><th>Klient</th><th>Stan</th><th>System</th><th>Adres IP</th><th>Zasoby</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($servers as $server)
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="{{ $server->id }}" style="width:auto"></td>
                                    <td><a href="{{ route('panel.servers.show', $server) }}">{{ $server->hostname }}</a></td>
                                    <td class="muted">{{ $server->user?->email ?? '—' }}</td>
                                    <td><span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span></td>
                                    <td class="muted">{{ $server->template?->name ?? '—' }}</td>
                                    <td class="mono">{{ $server->primaryIp()?->address ?? '—' }}</td>
                                    <td class="num">{{ $server->vcpu }} / {{ round($server->ram_mb / 1024, 1) }} GB / {{ $server->disk_gb }} GB</td>
                                    <td style="text-align:right; white-space:nowrap">
                                        @can('destroy', $server)
                                            <button class="btn btn-sm btn-danger" type="submit" name="row" value="delete:{{ $server->id }}"
                                                    data-confirm="Usunąć {{ $server->hostname }} razem z dyskiem?">Usuń</button>
                                        @endcan
                                        @if ($isAdmin)
                                            <button class="btn btn-sm" type="submit" name="row" value="purge:{{ $server->id }}"
                                                    data-confirm="Usunąć {{ $server->hostname }} TYLKO z panelu?">Z panelu</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>
        @endif
    </div>

    <div data-tab-panel="settings" role="tabpanel" hidden>
        <form method="POST" action="{{ route('panel.admin.hypervisors.update', $node) }}" class="card">
            @csrf @method('PUT')
            <h3 class="card-title"><x-icon name="node" :size="16"/> Ustawienia węzła</h3>
            <div class="grid grid-2">
                <div class="field">
                    <label for="n-name">Nazwa</label>
                    <input id="n-name" name="name" type="text" value="{{ old('name', $node->name) }}" required>
                </div>
                <div class="field">
                    <label for="n-group">Grupa węzłów</label>
                    <select id="n-group" name="hypervisor_group_id">
                        <option value="">— bez grupy —</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}" @selected($node->hypervisor_group_id === $group->id)>{{ $group->name }}</option>
                        @endforeach
                    </select>
                    <div class="hint">Węzeł korzysta z pul swojej grupy i z pul przypisanych do niego.</div>
                </div>
                <div class="field">
                    <label for="n-status">Tryb pracy</label>
                    <select id="n-status" name="status">
                        <option value="online" @selected($node->status === 'online')>Online</option>
                        <option value="maintenance" @selected($node->status === 'maintenance')>Konserwacja</option>
                        <option value="offline" @selected($node->status === 'offline')>Offline</option>
                    </select>
                    <div class="hint">Tryb konserwacji nie jest nadpisywany przez heartbeat węzła.</div>
                </div>
                <div class="field">
                    <label for="n-max">Limit maszyn <span class="muted">(opcjonalnie)</span></label>
                    <input id="n-max" name="max_servers" type="text" inputmode="numeric" value="{{ old('max_servers', $node->max_servers) }}" placeholder="bez limitu">
                    <div class="hint">Po osiągnięciu limitu węzeł nie dostaje nowych maszyn.</div>
                </div>
                <div class="field">
                    <label for="n-cpu">Dostępne vCPU</label>
                    <input id="n-cpu" name="cpu_cores_total" type="text" inputmode="numeric" value="{{ $node->cpu_cores_total }}" required>
                    <div class="hint">Zajęte: {{ $node->cpu_cores_used }}. Więcej niż rdzeni fizycznych = overcommit.</div>
                </div>
                <div class="field">
                    <label for="n-ram">Dostępna pamięć (MB)</label>
                    <input id="n-ram" name="ram_mb_total" type="text" inputmode="numeric" value="{{ $node->ram_mb_total }}" required>
                    <div class="hint">Zajęte: {{ $node->ram_mb_used }} MB</div>
                </div>
                <div class="field">
                    <label for="n-disk">Dostępny dysk (GB)</label>
                    <input id="n-disk" name="disk_gb_total" type="text" inputmode="numeric" value="{{ $node->disk_gb_total }}" required>
                    <div class="hint">Zajęte: {{ $node->disk_gb_used }} GB</div>
                </div>
                <div class="field">
                    <label for="n-bridge">Mostek sieciowy</label>
                    <input id="n-bridge" name="bridge" type="text" value="{{ $node->bridge }}" required>
                    <div class="hint">Interfejs, do którego podpinane są maszyny.</div>
                </div>
            </div>
            <div class="field">
                <label for="n-notes">Notatki <span class="muted">(dla personelu)</span></label>
                <textarea id="n-notes" name="notes" rows="3" placeholder="Dostawca, numer szafy, kontakt do DC…">{{ old('notes', $node->notes) }}</textarea>
            </div>
            <div class="field">
                <label class="check-line">
                    <input type="checkbox" name="accepts_new_servers" value="1" @checked($node->accepts_new_servers)>
                    Przyjmuje nowe maszyny
                </label>
                @if ($node->group && ! $node->group->accepts_new_servers)
                    <div class="hint">Grupa {{ $node->group->name }} ma wstrzymane przyjmowanie maszyn — to ustawienie jest nadrzędne.</div>
                @endif
            </div>
            <button class="btn btn-primary" type="submit">Zapisz ustawienia</button>
        </form>

        <div class="card danger-zone">
            <h3 class="card-title" style="color:var(--critical)">Usuwanie węzła</h3>
            @if ($node->servers_count === 0)
                <form method="POST" action="{{ route('panel.admin.hypervisors.destroy', $node) }}"
                      onsubmit="return confirm('Usunąć węzeł {{ $node->name }}?')" class="setting-row">
                    @csrf @method('DELETE')
                    <p class="muted setting-text">Węzeł nie ma maszyn — można go usunąć. Agenta na serwerze wyłącz ręcznie.</p>
                    <button class="btn btn-danger-solid" type="submit">Usuń węzeł</button>
                </form>
            @elseif ($isAdmin)
                <form method="POST" action="{{ route('panel.admin.hypervisors.destroy', $node) }}"
                      onsubmit="return confirm('Usunąć węzeł i WSZYSTKIE jego maszyny z panelu?')">
                    @csrf @method('DELETE')
                    <input type="hidden" name="purge_servers" value="1">
                    <p class="muted" style="margin-top:0">
                        Na węźle jest {{ $node->servers_count }} maszyn. Jeśli węzeł już nie istnieje, możesz usunąć go razem z
                        wpisami maszyn — tylko z panelu, bez kontaktu z węzłem. Adresy IP wrócą do pul.
                    </p>
                    <div class="field">
                        <label for="confirm-name">Wpisz <code>{{ $node->name }}</code>, żeby potwierdzić</label>
                        <input id="confirm-name" name="confirm_name" type="text" autocomplete="off" required>
                    </div>
                    <button class="btn btn-danger-solid" type="submit">Usuń węzeł i jego maszyny z panelu</button>
                </form>
            @else
                <p class="muted">Na węźle są maszyny — usuń je najpierw albo poproś administratora.</p>
            @endif
        </div>
    </div>

    <div data-tab-panel="info" role="tabpanel" hidden>
        <div class="grid grid-2">
            <div class="card">
                <h3 class="card-title">Połączenie</h3>
                <dl class="kv">
                    <dt>Adres agenta</dt><dd class="mono">{{ $node->agent_url ?? '—' }}</dd>
                    <dt>Zarejestrowany</dt><dd>{{ $node->enrolled_at?->format('d.m.Y H:i') ?? 'nie' }}</dd>
                    <dt>Ostatni kontakt</dt><dd>{{ $node->last_seen_at?->diffForHumans() ?? 'nigdy' }}</dd>
                    <dt>Certyfikat</dt><dd>{{ $node->agent_tls_cert ? 'przypięty (własny węzła)' : 'weryfikacja publicznym urzędem' }}</dd>
                    <dt>Wersja agenta</dt><dd class="mono">{{ \App\Domain\Updates\Updates::short($node->last_health['build'] ?? null) }}</dd>
                </dl>
                @if ($node->notes)
                    <p class="hint" style="white-space:pre-line; margin-top:14px">{{ $node->notes }}</p>
                @endif
            </div>
            <div class="card">
                <h3 class="card-title">Pule adresów węzła</h3>
                @forelse ($pools as $pool)
                    <p style="margin:0 0 8px">
                        <span class="mono">{{ $pool->name }}</span>
                        <span class="pill neutral">IPv{{ $pool->version }}{{ $pool->isNat() ? ' · NAT' : '' }}</span>
                        <span class="muted">{{ $pool->hypervisor_id ? 'węzła' : 'grupy' }}
                            @if ($pool->version === 4) · {{ $pool->assigned_count }} / {{ $pool->addresses_count }} zajętych @endif</span>
                    </p>
                @empty
                    <p class="muted">Brak pul — węzeł nie dostanie maszyn, dopóki nie przypiszesz mu adresów.</p>
                @endforelse
                <a class="btn btn-sm" href="{{ route('panel.admin.ip-pools') }}" style="margin-top:8px">Adresy IP</a>
            </div>
        </div>
        @if ($node->last_health)
            <details class="form-block card">
                <summary>Ostatni raport węzła</summary>
                <dl class="kv" style="margin-top:12px">
                    @foreach ($node->last_health as $key => $value)
                        <dt class="mono">{{ $key }}</dt>
                        <dd class="mono">{{ is_bool($value) ? ($value ? 'tak' : 'nie') : (is_array($value) ? json_encode($value) : $value) }}</dd>
                    @endforeach
                </dl>
            </details>
        @endif
    </div>

    @push('scripts')
        <script src="{{ asset('js/server-page.js') }}?v={{ @filemtime(public_path('js/server-page.js')) }}"></script>
    @endpush
@endsection
