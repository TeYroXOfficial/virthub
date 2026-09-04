@extends('layouts.panel')

@section('title', 'Moje maszyny')

@section('content')
    <h1>Moje maszyny</h1>
    <p class="lede">
        {{ $servers->count() }} {{ $servers->count() === 1 ? 'maszyna' : 'maszyn' }},
        z tego {{ $running }} działa@if($building), {{ $building }} w trakcie operacji@endif.
    </p>

    @if ($servers->isEmpty())
        <div class="card empty">
            <p>Nie masz jeszcze żadnej maszyny.</p>
            <a class="btn btn-primary" href="{{ route('panel.servers.create') }}">Zamów pierwszy VPS</a>
        </div>
    @else
        <div class="card" style="padding:0">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Nazwa hosta</th>
                        <th>Stan</th>
                        <th>Adres IP</th>
                        <th>System</th>
                        <th>Zasoby</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($servers as $server)
                        <tr>
                            <td>
                                <a href="{{ route('panel.servers.show', $server) }}">{{ $server->hostname }}</a>
                                @if ($server->label)
                                    <div class="hint">{{ $server->label }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span>
                            </td>
                            <td class="mono">{{ $server->primaryIp()?->address ?? '—' }}</td>
                            <td>{{ $server->template?->name ?? '—' }}</td>
                            <td class="num">
                                {{ $server->vcpu }} vCPU ·
                                {{ round($server->ram_mb / 1024, 1) }} GB ·
                                {{ $server->disk_gb }} GB
                            </td>
                            <td><a class="btn" href="{{ route('panel.servers.show', $server) }}">Zarządzaj</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="btn-row">
            <a class="btn btn-primary" href="{{ route('panel.servers.create') }}">Zamów kolejny VPS</a>
        </div>
    @endif

    @if ($fleet !== null)
        <h2>Flota hypervisorów</h2>
        <div class="grid grid-3">
            @forelse ($fleet as $node)
                <div class="card">
                    <h3>
                        {{ $node->name }}
                        <span class="pill {{ $node->isOnline() ? 'ok' : 'critical' }}" style="float:right">
                            {{ $node->isOnline() ? 'online' : $node->status }}
                        </span>
                    </h3>
                    <dl class="kv">
                        <dt>Maszyny</dt><dd class="num">{{ $node->servers()->count() }}</dd>
                        <dt>vCPU</dt><dd class="num">{{ $node->cpu_cores_used }} / {{ $node->cpu_cores_total }}</dd>
                        <dt>RAM</dt><dd class="num">{{ round($node->ram_mb_used / 1024) }} / {{ round($node->ram_mb_total / 1024) }} GB</dd>
                        <dt>Dysk</dt><dd class="num">{{ $node->disk_gb_used }} / {{ $node->disk_gb_total }} GB</dd>
                    </dl>
                    @php $util = $node->utilisationPercent(); @endphp
                    <div class="meter {{ $util > 80 ? 'hot' : '' }}">
                        <i style="width: {{ min(100, $util) }}%"></i>
                    </div>
                    <div class="hint">Zajętość {{ $util }}% · ostatni kontakt
                        {{ $node->last_seen_at?->diffForHumans() ?? 'nigdy' }}</div>
                </div>
            @empty
                <div class="card empty">
                    Brak zarejestrowanych hypervisorów. Dodaj pierwszy przez
                    <code>POST /api/v1/admin/hypervisors</code>.
                </div>
            @endforelse
        </div>
    @endif
@endsection
