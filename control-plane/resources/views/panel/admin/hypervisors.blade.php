@extends('layouts.panel')

@section('title', 'Hypervisory')

@php
    $pct = fn ($used, $total) => $total > 0 ? min(100, (int) round($used / $total * 100)) : 0;
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>Hypervisory</h1>
            <p class="lede">Węzły, na których stoją maszyny klientów, i ich grupy.</p>
        </div>
    </div>

    @include('panel.admin._nav')

    <details class="form-block card" @if($hypervisors->isEmpty()) open @endif>
        <summary>Dodaj węzeł</summary>
        <p style="margin-top:12px">
            Podaj tylko nazwę. Adres, liczba rdzeni, pamięć i pojemność dysku zostaną
            wykryte automatycznie, kiedy węzeł zgłosi się po instalacji.
        </p>
        <p class="hint">
            Rodzaj węzła instalator wykrywa sam: serwer z VT-x/AMD-V dostaje KVM, a serwer bez niego (np. VPS) —
            kontenery LXC. Żeby wymusić kontenery na serwerze z KVM, uruchom polecenie z
            <span class="mono">sudo VH_VIRT=lxc bash</span> zamiast <span class="mono">sudo bash</span>.
        </p>
        <form method="POST" action="{{ route('panel.admin.hypervisors.store') }}">
            @csrf
            <div class="field">
                <label for="name">Nazwa węzła</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" placeholder="node1" required>
                <div class="hint">Krótka nazwa robocza, widoczna tylko dla personelu.</div>
            </div>
            <button class="btn btn-primary" type="submit">Dodaj i wygeneruj polecenie instalacyjne</button>
        </form>
    </details>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Węzeł</th><th>Stan</th><th>Grupa</th><th>CPU</th><th>RAM</th><th>Dysk</th><th>Maszyny</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($hypervisors as $node)
                    <tr>
                        <td>
                            <a href="{{ route('panel.admin.hypervisors.show', $node) }}"><strong>{{ $node->name }}</strong></a>
                            <div class="hint">{{ $node->enrolled_at ? $node->virtualization->shortLabel().' · '.$node->hostname : 'czeka na instalację' }}</div>
                        </td>
                        <td>
                            @if (! $node->enrolled_at)
                                <span class="pill warning">nowy</span>
                            @elseif ($node->status === 'maintenance')
                                <span class="pill warning">konserwacja</span>
                            @else
                                <span class="pill {{ $node->isOnline() ? 'ok' : 'critical' }}">{{ $node->isOnline() ? 'online' : 'offline' }}</span>
                            @endif
                            @unless ($node->accepts_new_servers)
                                <div class="hint">nie przyjmuje maszyn</div>
                            @endunless
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
                                    <button class="btn btn-sm btn-primary" type="submit">Polecenie instalacyjne</button>
                                </form>
                            @endif
                            <a class="btn btn-sm" href="{{ route('panel.admin.hypervisors.show', $node) }}">Zarządzaj</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Brak węzłów. Dodaj pierwszy — dostaniesz jedno polecenie do wklejenia na serwerze.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('panel.admin._groups')
@endsection
