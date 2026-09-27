@extends('layouts.panel')

@section('title', __('Hypervisory'))

@php
    $pct = fn ($used, $total) => $total > 0 ? min(100, (int) round($used / $total * 100)) : 0;
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Hypervisory') }}</h1>
            <p class="lede">{{ __('Węzły, na których stoją maszyny klientów, i ich grupy.') }}</p>
        </div>
    </div>

    @include('panel.admin._nav')

    <details class="form-block card" @if($hypervisors->isEmpty()) open @endif>
        <summary>{{ __('Dodaj węzeł') }}</summary>
        <p style="margin-top:12px">
            {{ __('Podaj tylko nazwę. Adres, liczba rdzeni, pamięć i pojemność dysku zostaną wykryte automatycznie, kiedy węzeł zgłosi się po instalacji.') }}
        </p>
        <p class="hint">
            {{ __('Rodzaj węzła instalator wykrywa sam: serwer z VT-x/AMD-V dostaje KVM, a serwer bez niego (np. VPS) — kontenery LXC. Żeby wymusić kontenery na serwerze z KVM, uruchom polecenie z') }}
            <span class="mono">{{ __('sudo VH_VIRT=lxc bash') }}</span> {{ __('zamiast') }} <span class="mono">{{ __('sudo bash') }}</span>.
        </p>
        <form method="POST" action="{{ route('panel.admin.hypervisors.store') }}">
            @csrf
            <div class="field">
                <label for="name">{{ __('Nazwa węzła') }}</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="{{ __('node1') }}" required>
                <div class="hint">{{ __('Krótka nazwa robocza, widoczna tylko dla personelu.') }}</div>
            </div>
            <button class="btn btn-primary" type="submit">{{ __('Dodaj i wygeneruj polecenie instalacyjne') }}</button>
        </form>
    </details>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __('Węzeł') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Grupa') }}</th><th>{{ __('CPU') }}</th><th>{{ __('RAM') }}</th><th>{{ __('Dysk') }}</th><th>{{ __('Maszyny') }}</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($hypervisors as $node)
                    <tr>
                        <td>
                            <a href="{{ route('panel.admin.hypervisors.show', $node) }}"><strong>{{ $node->name }}</strong></a>
                            <div class="hint">{{ $node->enrolled_at ? $node->virtualization->shortLabel().' · '.$node->hostname : __('czeka na instalację') }}</div>
                            @if ($node->cpuModel())
                                <div class="hint">{{ $node->cpuModel() }}</div>
                            @endif
                        </td>
                        <td>
                            @if (! $node->enrolled_at)
                                <span class="pill warning">{{ __('nowy') }}</span>
                            @elseif ($node->status === 'maintenance')
                                <span class="pill warning">{{ __('konserwacja') }}</span>
                            @else
                                <span class="pill {{ $node->isOnline() ? 'ok' : 'critical' }}">{{ $node->isOnline() ? __('online') : __('offline') }}</span>
                            @endif
                            @unless ($node->accepts_new_servers)
                                <div class="hint">{{ __('nie przyjmuje maszyn') }}</div>
                            @endunless
                            @if (! empty($node->last_health['security']['instance_issues']) || (isset($node->last_health['security']['host_guard']) && ! $node->last_health['security']['host_guard']))
                                <a class="pill critical" href="{{ route('panel.admin.hypervisors.show', $node) }}#security" style="text-decoration:none">{{ __('izolacja!') }}</a>
                            @endif
                        </td>
                        <td class="muted">{{ $node->group?->name ?? '—' }}</td>
                        @foreach ([[$node->cpu_cores_used, $node->cpu_cores_total, ''], [$node->ram_mb_used, $node->ram_mb_total, ' MB'], [$node->disk_gb_used, $node->disk_gb_total, ' GB']] as [$u, $t, $unit])
                            <td style="min-width:110px">
                                <span class="num" style="font-size:12.5px">{{ $u }} / {{ $t }}{{ $unit }}</span>
                                <div class="meter {{ $pct($u, $t) > 85 ? 'hot' : '' }}"><i style="width: {{ $pct($u, $t) }}%"></i></div>
                            </td>
                        @endforeach
                        <td class="num">
                            {{ $node->servers_count }}@if ($node->max_servers !== null)<span class="muted"> / {{ $node->max_servers }}</span>@endif
                        </td>
                        <td style="text-align:right">
                            @if ($node->enrolled_at === null)
                                <form method="POST" action="{{ route('panel.admin.hypervisors.enrollment', $node) }}" style="display:inline">
                                    @csrf
                                    <button class="btn btn-sm btn-primary" type="submit">{{ __('Polecenie instalacyjne') }}</button>
                                </form>
                            @endif
                            <a class="btn btn-sm" href="{{ route('panel.admin.hypervisors.show', $node) }}">{{ __('Zarządzaj') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">{{ __('Brak węzłów. Dodaj pierwszy — dostaniesz jedno polecenie do wklejenia na serwerze.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('panel.admin._groups')
@endsection
