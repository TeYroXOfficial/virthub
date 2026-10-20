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
        @elseif (\App\Domain\Billing\Billing::enabled())
            <div class="actions">
                <a class="btn btn-primary" href="{{ route('panel.store') }}"><x-icon name="plus" :size="16"/> {{ __('Zamów serwer') }}</a>
            </div>
        @endcan
    </div>

    {{-- Flota (personel) w kolumnie bocznej na szerokim ekranie, pod listą na wąskim. --}}
    <div class="{{ $fleet !== null ? 'dash-layout' : '' }}">
    <div class="dash-main">
    @if ($servers->isNotEmpty())
        <div class="grid stat-strip">
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

    @if ($servers->isEmpty() && $cloud->isEmpty())
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
                                @if ($server->user_id !== auth()->id() && ! auth()->user()->isStaff()) <span class="pill neutral plain">{{ __('udostępniona') }}</span> @endif
                                @if ($server->label)
                                    <div class="hint">{{ $server->label }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span>
                            </td>
                            <td class="mono">{{ $server->primaryIp()?->publicAddress() ?? '—' }}</td>
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
                    @foreach ($cloud as $vm)
                        <tr>
                            <td><a href="{{ route('panel.cloud.show', $vm) }}" style="font-weight:600">{{ $vm->name }}</a>
                                @if ($vm->name !== $vm->hostname) <div class="hint">{{ $vm->hostname }}</div> @endif</td>
                            <td><span class="pill {{ $vm->statusTone() }}">{{ $vm->statusLabel() }}</span></td>
                            <td class="mono">{{ $vm->ipv4 ?? '—' }}</td>
                            <td>{{ $vm->image_name ?? '—' }}</td>
                            <td class="num">@if ($vm->cpu) {{ __(':vcpu vCPU · :ram_mb GB · :disk_gb GB', ['vcpu' => $vm->cpu, 'ram_mb' => round($vm->ram_mb / 1024, 1), 'disk_gb' => $vm->disk_gb]) }} @else — @endif</td>
                            <td style="text-align:right"><a class="btn btn-sm" href="{{ route('panel.cloud.show', $vm) }}">{{ __('Zarządzaj') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    </div>

    @if ($fleet !== null)
        <aside class="dash-aside">
        <h2 style="margin-top:0">{{ __('Flota hypervisorów') }}</h2>
        <div class="card-grid">
            @forelse ($fleet as $node)
                <div class="card">
                    <h3 class="card-title">
                        {{ $node->name }}
                        <span class="pill {{ $node->isOnline() ? 'ok' : 'critical' }}" style="margin-left:auto">
                            {{ $node->isOnline() ? __('online') : $node->status }}
                        </span>
                    </h3>
                    @php
                        $util = $node->utilisationPercent();
                        $rows = [
                            [__('vCPU'), $node->cpu_cores_used, $node->cpu_cores_total, ''],
                            [__('RAM'), round($node->ram_mb_used / 1024), round($node->ram_mb_total / 1024), ' GB'],
                            [__('Dysk'), $node->disk_gb_used, $node->disk_gb_total, ' GB'],
                        ];
                    @endphp
                    <div class="node-meters">
                        @foreach ($rows as [$label, $used, $total, $unit])
                            @php $pct = $total > 0 ? (int) round(100 * $used / $total) : 0; @endphp
                            <div class="node-meter">
                                <span>{{ $label }}</span>
                                <span class="num">{{ $used }} / {{ $total }}{{ $unit }}</span>
                                <div class="meter {{ $pct >= 90 ? 'critical' : ($pct >= 75 ? 'hot' : '') }}"><i style="width: {{ min(100, $pct) }}%"></i></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="hint">{{ trans_choice(':count maszyna|:count maszyny|:count maszyn', $node->servers()->count()) }} · {{ __('Zajętość :util% · ostatni kontakt :nigdy', ['util' => $util, 'nigdy' => $node->last_seen_at?->diffForHumans() ?? __('nigdy')]) }}</div>
                </div>
            @empty
                <div class="card empty">
                    {{ __('Brak zarejestrowanych hypervisorów. Dodaj pierwszy przez') }}
                    <code>POST /api/v1/admin/hypervisors</code>.
                </div>
            @endforelse
        </div>
        </aside>
    @endif
    </div>
@endsection
