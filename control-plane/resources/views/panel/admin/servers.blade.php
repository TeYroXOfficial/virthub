@extends('layouts.panel')

@section('title', 'Wszystkie maszyny')

@section('content')
    <h1>Maszyny</h1>
    <p class="lede">Wszystkie maszyny w systemie, niezależnie od właściciela.</p>

    @include('panel.admin._nav')

    <div class="card">
        <form method="GET" action="{{ route('panel.admin.servers') }}">
            <div class="grid grid-2">
                <div class="field" style="margin-bottom:0">
                    <label for="q">Szukaj</label>
                    <input id="q" name="q" type="text" value="{{ request('q') }}"
                           placeholder="nazwa hosta albo e-mail klienta">
                </div>
                <div class="field" style="margin-bottom:0">
                    <label for="state">Stan</label>
                    <select id="state" name="state">
                        <option value="">wszystkie</option>
                        @foreach ($states as $state)
                            <option value="{{ $state->value }}" @selected(request('state') === $state->value)>
                                {{ $state->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="btn-row" style="margin-top:14px">
                <button class="btn btn-primary" type="submit">Filtruj</button>
                @if (request('q') || request('state'))
                    <a class="btn" href="{{ route('panel.admin.servers') }}">Wyczyść</a>
                @endif
            </div>
        </form>
    </div>

    @php $isAdmin = auth()->user()->isAdmin(); @endphp
    <form method="POST" action="{{ route('panel.admin.servers.bulk') }}" id="servers-bulk"
          onsubmit="return window.vhConfirmBulk(event)">
        @csrf
        <div class="bulk-bar" data-bulk-bar hidden>
            <span><strong data-bulk-count>0</strong> zaznaczonych</span>
            <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete">Usuń zaznaczone</button>
            @if ($isAdmin)
                <button class="btn btn-sm" type="submit" name="action" value="purge"
                        title="Bez kontaktu z węzłem — dla martwych wpisów">Usuń zaznaczone tylko z panelu</button>
            @endif
        </div>

        <div class="card" style="padding:0">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th style="width:32px"><input type="checkbox" data-check-all aria-label="Zaznacz wszystkie" style="width:auto"></th>
                        <th>Maszyna</th><th>Klient</th><th>Stan</th><th>Adres IP</th>
                        <th>Węzeł</th><th>Zapora</th><th></th></tr>
                    </thead>
                    <tbody>
                    @forelse ($servers as $server)
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="{{ $server->id }}" data-check style="width:auto"
                                       aria-label="Zaznacz {{ $server->hostname }}"></td>
                            <td>
                                <a href="{{ route('panel.servers.show', $server) }}">{{ $server->hostname }}</a>
                                <div class="hint">
                                    {{ $server->vcpu }} vCPU · {{ round($server->ram_mb / 1024, 1) }} GB · {{ $server->disk_gb }} GB
                                    · {{ $server->created_at->format('d.m.Y') }}@if ($server->label) · {{ $server->label }}@endif
                                </div>
                            </td>
                            <td>
                                {{ $server->user?->email ?? '—' }}
                            </td>
                            <td>
                                <span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span>
                                @if ($server->isSuspended())
                                    <div class="hint">{{ $server->suspension_reason }}</div>
                                @elseif ($server->state === \App\Enums\ServerState::Error && $server->state_message)
                                    <div class="hint" style="max-width:260px">{{ \Illuminate\Support\Str::limit($server->state_message, 90) }}</div>
                                @endif
                            </td>
                            <td class="mono">{{ $server->primaryIp()?->address ?? '—' }}</td>
                            <td class="muted">
                                @if ($server->hypervisor)
                                    <a href="{{ route('panel.admin.hypervisors.show', $server->hypervisor) }}">{{ $server->hypervisor->name }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('panel.servers.show', $server) }}#firewall" style="text-decoration:none">
                                    <span class="pill {{ $server->firewall_enabled ? ($server->firewall_inbound === 'drop' ? 'ok' : 'info') : 'neutral' }}">
                                        {{ $server->firewall_enabled ? ($server->firewall_inbound === 'drop' ? 'restrykcyjna' : 'włączona') : 'wyłączona' }}
                                    </span>
                                </a>
                                @if ($server->firewall_locked)
                                    <div class="hint">zablokowana</div>
                                @endif
                            </td>
                            <td class="row-actions">
                                @can('destroy', $server)
                                    <button class="btn btn-sm btn-danger" type="submit" name="row" value="delete:{{ $server->id }}"
                                            @disabled($server->state === \App\Enums\ServerState::Deleting)
                                            data-confirm="Usunąć {{ $server->hostname }} razem z dyskiem?">Usuń</button>
                                @endcan
                                @if ($isAdmin)
                                    <button class="btn btn-sm" type="submit" name="row" value="purge:{{ $server->id }}"
                                            title="Usuń wpis bez kontaktu z węzłem (węzeł nie istnieje, maszyna zniknęła, operacja utknęła)"
                                            data-confirm="Usunąć {{ $server->hostname }} TYLKO z panelu? Panel nie skontaktuje się z węzłem — jeśli maszyna tam działa, trzeba ją usunąć ręcznie.">Z panelu</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="muted">Brak maszyn spełniających kryteria.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <p class="hint">
        <strong>Usuń</strong> kasuje maszynę na węźle i dopiero potem zwalnia adresy IP.
        @if ($isAdmin)
            <strong>Z panelu</strong> usuwa sam wpis — dla maszyn, których węzeł już nie istnieje albo nie odpowiada,
            i dla operacji, które utknęły.
        @endif
    </p>

    <script>
        (() => {
            const form = document.getElementById('servers-bulk');
            const boxes = [...form.querySelectorAll('[data-check]')];
            const bar = form.querySelector('[data-bulk-bar]');
            const count = form.querySelector('[data-bulk-count]');
            const sync = () => {
                const n = boxes.filter((b) => b.checked).length;
                bar.hidden = n === 0;
                count.textContent = n;
            };
            form.querySelector('[data-check-all]')?.addEventListener('change', (e) => {
                boxes.forEach((b) => { b.checked = e.target.checked; });
                sync();
            });
            boxes.forEach((b) => b.addEventListener('change', sync));

            window.vhConfirmBulk = (event) => {
                const button = event.submitter;
                if (button?.dataset.confirm) return confirm(button.dataset.confirm);
                const n = boxes.filter((b) => b.checked).length;
                return confirm(button?.value === 'purge'
                    ? `Usunąć ${n} maszyn TYLKO z panelu, bez kontaktu z węzłami?`
                    : `Usunąć ${n} maszyn razem z dyskami?`);
            };
        })();
    </script>

    {{ $servers->links() }}
@endsection
