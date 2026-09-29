@extends('layouts.panel')

@section('title', __('Wszystkie usługi'))

@section('content')
    <h1>{{ __('Wszystkie usługi') }}</h1>
    <p class="lede">{{ __('Maszyny wirtualne, kontenery i aplikacje wszystkich klientów w jednym spisie.') }}</p>

    <div class="stats" style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px">
        @foreach ([['kvm', __('Maszyny KVM')], ['lxc', __('Kontenery')], ['app', __('Aplikacje')]] as [$key, $label])
            @continue($totals[$key] === null)
            <a class="card" href="{{ route('panel.admin.services', ['kind' => $key]) }}" style="flex:1; min-width:160px; margin:0; text-decoration:none">
                <div class="muted">{{ $label }}</div>
                <div style="font-size:26px; font-weight:700">{{ $totals[$key] }}</div>
            </a>
        @endforeach
    </div>

    <div class="card">
        <form method="GET" action="{{ route('panel.admin.services') }}">
            <div class="grid-compact">
                <div class="field">
                    <label for="s-q">{{ __('Szukaj') }}</label>
                    <input id="s-q" name="q" type="search" value="{{ request('q') }}" placeholder="{{ __('nazwa, adres IP, UUID albo klient') }}">
                </div>
                <div class="field">
                    <label for="s-kind">{{ __('Rodzaj') }}</label>
                    <select id="s-kind" name="kind">
                        <option value="">{{ __('wszystkie') }}</option>
                        @if ($canServers)
                            <option value="kvm" @selected(request('kind') === 'kvm')>{{ __('Maszyny KVM') }}</option>
                            <option value="lxc" @selected(request('kind') === 'lxc')>{{ __('Kontenery') }}</option>
                        @endif
                        @if ($canApps)
                            <option value="app" @selected(request('kind') === 'app')>{{ __('Aplikacje') }}</option>
                        @endif
                    </select>
                </div>
                <div class="field">
                    <label for="s-node">{{ __('Węzeł') }}</label>
                    <select id="s-node" name="node">
                        <option value="">{{ __('wszystkie') }}</option>
                        @foreach ($nodes as $node)
                            <option value="{{ $node->id }}" @selected((int) request('node') === $node->id)>{{ $node->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="s-status">{{ __('Stan') }}</label>
                    <select id="s-status" name="status">
                        <option value="">{{ __('wszystkie') }}</option>
                        <option value="problem" @selected(request('status') === 'problem')>{{ __('z problemem (błąd, zawieszone)') }}</option>
                        <option value="suspended" @selected(request('status') === 'suspended')>{{ __('zawieszone') }}</option>
                    </select>
                </div>
            </div>
            <div class="btn-row">
                <button class="btn btn-primary" type="submit">{{ __('Filtruj') }}</button>
                @if (request()->hasAny(['q', 'kind', 'node', 'status']))
                    <a class="btn" href="{{ route('panel.admin.services') }}">{{ __('Wyczyść') }}</a>
                @endif
            </div>
        </form>
    </div>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __('Usługa') }}</th><th>{{ __('Rodzaj') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Adres') }}</th><th>{{ __('Węzeł') }}</th><th>{{ __('Zasoby') }}</th><th>{{ __('Utworzona') }}</th></tr>
                </thead>
                <tbody>
                @forelse ($items as $item)
                    @if ($item instanceof \App\Models\Server)
                        <tr>
                            <td>
                                <span class="os-inline">
                                    @if ($item->osFamily())
                                        @include('panel.servers._os-badge', ['template' => (object) ['family' => $item->osFamily(), 'name' => $item->osLabel()]])
                                    @endif
                                    <a href="{{ route('panel.servers.show', $item) }}"><strong>{{ $item->hostname }}</strong></a>
                                </span>
                                <div class="hint">{{ $item->osLabel() ?? __('system nieznany') }}@if ($item->label) · {{ $item->label }}@endif</div>
                            </td>
                            <td><span class="pill neutral">{{ $item->virtualization->shortLabel() }}</span></td>
                            <td class="muted">{{ $item->user?->email ?? '—' }}</td>
                            <td>
                                <span class="pill {{ $item->state->tone() }}">{{ $item->state->label() }}</span>
                                @if ($item->isSuspended() && $item->suspension_reason)
                                    <div class="hint">{{ \Illuminate\Support\Str::limit($item->suspension_reason, 80) }}</div>
                                @endif
                            </td>
                            <td class="mono">{{ $item->primaryIp()?->address ?? '—' }}</td>
                            <td class="muted">
                                @if ($item->hypervisor)
                                    <a href="{{ route('panel.admin.hypervisors.show', $item->hypervisor) }}">{{ $item->hypervisor->name }}</a>
                                @else — @endif
                            </td>
                            <td class="num">{{ __(':vcpu vCPU · :ram GB · :disk GB', ['vcpu' => $item->vcpu, 'ram' => round($item->ram_mb / 1024, 1), 'disk' => $item->disk_gb]) }}</td>
                            <td class="muted">{{ $item->created_at->format('d.m.Y') }}</td>
                        </tr>
                    @else
                        <tr>
                            <td>
                                <a href="{{ route('panel.apps.show', $item) }}"><strong>{{ $item->name }}</strong></a>
                                <div class="hint">{{ $item->egg?->displayName() ?? '—' }}</div>
                            </td>
                            <td><span class="pill info">{{ __('Aplikacja') }}</span></td>
                            <td class="muted">{{ $item->user?->email ?? '—' }}</td>
                            <td>
                                <span class="pill {{ $item->isSuspended() || $item->status === 'install_failed' ? 'critical' : ($item->isInstalling() ? 'warning' : 'ok') }}">{{ $item->statusLabel() }}</span>
                                @if ($item->abuse_detected_at) <span class="pill critical plain" title="{{ $item->suspension_reason }}">{{ __('nadużycie') }}</span> @endif
                            </td>
                            <td class="mono">{{ $item->address() ?? '—' }}</td>
                            <td class="muted">
                                @if ($item->hypervisor)
                                    <a href="{{ route('panel.admin.hypervisors.show', $item->hypervisor) }}">{{ $item->hypervisor->name }}</a>
                                @else — @endif
                            </td>
                            <td class="num">{{ __(':memory MB · :disk MB', ['memory' => $item->memory_mb, 'disk' => $item->disk_mb]) }}</td>
                            <td class="muted">{{ $item->created_at->format('d.m.Y') }}</td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="8" class="muted" style="text-align:center; padding:24px">{{ __('Brak usług spełniających kryteria.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $page->links() }}
@endsection
