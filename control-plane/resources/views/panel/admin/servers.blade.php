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

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Maszyna</th><th>Klient</th><th>Stan</th><th>Adres IP</th>
                    <th>Węzeł</th><th>Zasoby</th><th>Zapora</th><th>Utworzona</th></tr>
                </thead>
                <tbody>
                @forelse ($servers as $server)
                    <tr>
                        <td>
                            <a href="{{ route('panel.servers.show', $server) }}">{{ $server->hostname }}</a>
                            @if ($server->label)
                                <div class="hint">{{ $server->label }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $server->user?->email ?? '—' }}
                        </td>
                        <td>
                            <span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span>
                            @if ($server->isSuspended())
                                <div class="hint">{{ $server->suspension_reason }}</div>
                            @endif
                        </td>
                        <td class="mono">{{ $server->primaryIp()?->address ?? '—' }}</td>
                        <td class="muted">{{ $server->hypervisor?->name ?? '—' }}</td>
                        <td class="num">
                            {{ $server->vcpu }} / {{ round($server->ram_mb / 1024, 1) }} GB / {{ $server->disk_gb }} GB
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
                        <td class="muted">{{ $server->created_at->format('d.m.Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Brak maszyn spełniających kryteria.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $servers->links() }}
@endsection
