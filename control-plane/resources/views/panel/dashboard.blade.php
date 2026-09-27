@extends('layouts.panel')

@section('title', __('Moje maszyny'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Moje maszyny') }}</h1>
            <p class="lede">{{ __('Serwery na Twoim koncie — stan, adresy i szybki dostęp do zarządzania.') }}</p>
        </div>
        @can('create', \App\Models\Server::class)
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('panel.servers.create') }}"><x-icon name="plus" :size="16"/> {{ __('Zamów serwer') }}</a>
            </div>
        @endcan
    </div>

    @if ($servers->isNotEmpty())
        <div class="grid grid-4" style="margin-bottom:20px">
            <div class="stat">
                <div class="stat-label">{{ __('Wszystkie') }}</div>
                <div class="stat-value">{{ $servers->count() }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">{{ __('Działające') }}</div>
                <div class="stat-value" style="color:var(--ok)">{{ $running }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">{{ __('W trakcie operacji') }}</div>
                <div class="stat-value" style="color:var(--warn)">{{ $building }}</div>
            </div>
            <div class="stat">
                <div class="stat-label">{{ __('Łącznie vCPU / RAM') }}</div>
                <div class="stat-value">{{ $servers->sum('vcpu') }} <span class="stat-sub">{{ __('vCPU') }}</span>
                    · {{ round($servers->sum('ram_mb') / 1024, 1) }} <span class="stat-sub">{{ __('GB') }}</span></div>
            </div>
        </div>
    @endif

    @if ($servers->isEmpty())
        <div class="card empty">
            <x-icon name="servers" :size="40"/>
            <p>{{ __('Nie masz jeszcze żadnej maszyny.') }}</p>
            @can('create', \App\Models\Server::class)
                <a class="btn btn-primary" href="{{ route('panel.servers.create') }}">{{ __('Zamów pierwszy VPS') }}</a>
            @endcan
        </div>
    @else
        <div class="card" style="padding:0">
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('Nazwa hosta') }}</th>
                        <th>{{ __('Stan') }}</th>
                        <th>{{ __('Adres IP') }}</th>
                        <th>{{ __('System') }}</th>
                        <th>{{ __('Zasoby') }}</th>
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
                            <td>
                                @if ($server->osLabel())
                                    <span class="os-inline">
                                        @include('panel.servers._os-badge', ['template' => (object) ['family' => $server->osFamily(), 'name' => $server->osLabel()]])
                                        {{ $server->osLabel() }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="num">
                                {{ __(':vcpu vCPU · :ram_mb GB · :disk_gb GB', ['vcpu' => $server->vcpu, 'ram_mb' => round($server->ram_mb / 1024, 1), 'disk_gb' => $server->disk_gb]) }}
                            </td>
                            <td style="text-align:right"><a class="btn btn-sm" href="{{ route('panel.servers.show', $server) }}">{{ __('Zarządzaj') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($fleet !== null)
        <h2>{{ __('Flota hypervisorów') }}</h2>
        <div class="grid grid-3">
            @forelse ($fleet as $node)
                <div class="card">
                    <h3>
                        {{ $node->name }}
                        <span class="pill {{ $node->isOnline() ? 'ok' : 'critical' }}" style="float:right">
                            {{ $node->isOnline() ? __('online') : $node->status }}
                        </span>
                    </h3>
                    <dl class="kv">
                        <dt>{{ __('Maszyny') }}</dt><dd class="num">{{ $node->servers()->count() }}</dd>
                        <dt>{{ __('vCPU') }}</dt><dd class="num">{{ $node->cpu_cores_used }} / {{ $node->cpu_cores_total }}</dd>
                        <dt>{{ __('RAM') }}</dt><dd class="num">{{ __(':ram_mb_used / :ram_mb_total GB', ['ram_mb_used' => round($node->ram_mb_used / 1024), 'ram_mb_total' => round($node->ram_mb_total / 1024)]) }}</dd>
                        <dt>{{ __('Dysk') }}</dt><dd class="num">{{ __(':disk_gb_used / :disk_gb_total GB', ['disk_gb_used' => $node->disk_gb_used, 'disk_gb_total' => $node->disk_gb_total]) }}</dd>
                    </dl>
                    @php $util = $node->utilisationPercent(); @endphp
                    <div class="meter {{ $util > 80 ? 'hot' : '' }}">
                        <i style="width: {{ min(100, $util) }}%"></i>
                    </div>
                    <div class="hint">{{ __('Zajętość :util% · ostatni kontakt :nigdy', ['util' => $util, 'nigdy' => $node->last_seen_at?->diffForHumans() ?? __('nigdy')]) }}</div>
                </div>
            @empty
                <div class="card empty">
                    {{ __('Brak zarejestrowanych hypervisorów. Dodaj pierwszy przez') }}
                    <code>POST /api/v1/admin/hypervisors</code>.
                </div>
            @endforelse
        </div>
    @endif
@endsection
