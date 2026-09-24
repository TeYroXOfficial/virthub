@extends('layouts.panel')

@section('title', 'Moje maszyny')

@section('content')
    <div class="page-header">
        <div>
            <h1>Moje maszyny</h1>
            <p class="lede">Serwery na Twoim koncie — stan, adresy i szybki dostęp do zarządzania.</p>
        </div>
        @can('create', \App\Models\Server::class)
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('panel.servers.create') }}"><x-icon name="plus" :size="16"/> Zamów serwer</a>
            </div>
        @endcan
    </div>

    @if ($servers->isNotEmpty())
        <div class="grid grid-4" style="margin-bottom:20px">
            <div class="stat">
                <div class="stat-label">Wszystkie</div>
                <div class="stat-value">{{ $servers->count() }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">Działające</div>
                <div class="stat-value" style="color:var(--ok)">{{ $running }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">W trakcie operacji</div>
                <div class="stat-value" style="color:var(--warn)">{{ $building }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">Łącznie vCPU / RAM</div>
                <div class="stat-value">{{ $servers->sum('vcpu') }} <span class="stat-sub">vCPU</span>
                    · {{ round($servers->sum('ram_mb') / 1024, 1) }} <span class="stat-sub">GB</span></div>
            </div>
        </div>
    @endif

    @if ($servers->isEmpty())
        <div class="card empty">
            <x-icon name="servers" :size="40"/>
            <p>Nie masz jeszcze żadnej maszyny.</p>
            @can('create', \App\Models\Server::class)
                <a class="btn btn-primary" href="{{ route('panel.servers.create') }}">Zamów pierwszy VPS</a>
            @endcan
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
                                <a href="{{ route('panel.servers.show', $server) }}" style="font-weight:600">{{ $server->hostname }}</a>
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
                            <td style="text-align:right"><a class="btn btn-sm" href="{{ route('panel.servers.show', $server) }}">Zarządzaj</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
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
