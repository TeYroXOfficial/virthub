@extends('layouts.panel')

@section('title', 'Administracja')

@section('content')
    <h1>Administracja</h1>
    <p class="lede">Stan floty, maszyn i zasobów.</p>

    @include('panel.admin._nav')

    <div class="grid grid-3">
        <div class="card">
            <h3>Maszyny</h3>
            <p style="font-size:28px; margin:0; font-variant-numeric: tabular-nums;">{{ $serversTotal }}</p>
            {{-- Warunek w wyrażeniu, nie dyrektywą: Blade nie kompiluje @if
                 przyklejonego do poprzedzającego słowa i zostawia go w treści. --}}
            <p class="hint">
                {{ $serversRunning }} działa{{ $serversBroken ? ", {$serversBroken} wymaga uwagi" : '' }}
            </p>
        </div>
        <div class="card">
            <h3>Klienci</h3>
            <p style="font-size:28px; margin:0; font-variant-numeric: tabular-nums;">{{ $customers }}</p>
            <p class="hint">konta z rolą klienta</p>
        </div>
        <div class="card">
            <h3>Wolne adresy IP</h3>
            <p style="font-size:28px; margin:0; font-variant-numeric: tabular-nums;">{{ $addressesFree }}</p>
            <p class="hint">z {{ $addressesTotal }} w pulach</p>
            @if ($addressesTotal > 0 && $addressesFree < 5)
                <p class="hint" style="color: var(--warn)">Pula na wyczerpaniu — zaimportuj kolejną.</p>
            @endif
        </div>
    </div>

    <h2>Flota</h2>
    @forelse ($hypervisors as $node)
        <div class="card">
            <h3>
                {{ $node->name }}
                <span class="pill {{ $node->isOnline() ? 'ok' : ($node->enrolled_at ? 'critical' : 'warning') }}"
                      style="float:right">
                    {{ $node->isOnline() ? 'online' : ($node->enrolled_at ? $node->status : 'czeka na instalację') }}
                </span>
            </h3>

            @if ($node->enrolled_at === null)
                <p class="muted">
                    Węzeł dodany, ale agent nie został jeszcze zainstalowany.
                    Wygeneruj polecenie instalacyjne na
                    <a href="{{ route('panel.admin.hypervisors') }}">liście hypervisorów</a>.
                </p>
            @else
                <dl class="kv">
                    <dt>Rodzaj</dt><dd>{{ $node->virtualization->label() }}</dd>
                    <dt>Adres</dt><dd class="mono">{{ $node->agent_url }}</dd>
                    <dt>Maszyny</dt><dd class="num">{{ $node->servers_count }}</dd>
                    <dt>vCPU</dt><dd class="num">{{ $node->cpu_cores_used }} / {{ $node->cpu_cores_total }}</dd>
                    <dt>RAM</dt><dd class="num">{{ round($node->ram_mb_used / 1024) }} / {{ round($node->ram_mb_total / 1024) }} GB</dd>
                    <dt>Dysk</dt><dd class="num">{{ $node->disk_gb_used }} / {{ $node->disk_gb_total }} GB</dd>
                    <dt>Ostatni kontakt</dt><dd>{{ $node->last_seen_at?->diffForHumans() ?? 'nigdy' }}</dd>
                </dl>
                @php $util = $node->utilisationPercent(); @endphp
                <div class="meter {{ $util > 80 ? 'hot' : '' }}"><i style="width: {{ min(100, $util) }}%"></i></div>
                <div class="hint">Zajętość {{ $util }}%</div>
            @endif
        </div>
    @empty
        <div class="card empty">
            <p>Nie masz jeszcze żadnego hypervisora. Bez niego nie da się utworzyć maszyny.</p>
            <a class="btn btn-primary" href="{{ route('panel.admin.hypervisors') }}">Dodaj pierwszy węzeł</a>
        </div>
    @endforelse

    <h2>Ostatnie zdarzenia</h2>
    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Zdarzenie</th><th>Kto</th><th>Czego dotyczy</th><th>Kiedy</th></tr></thead>
                <tbody>
                @forelse ($recentLogs as $log)
                    <tr>
                        <td class="mono">{{ $log->action }}</td>
                        <td>{{ $log->actor?->email ?? $log->actor_label }}</td>
                        <td class="muted">
                            {{ $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '—' }}
                        </td>
                        <td class="muted">{{ $log->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Brak zdarzeń.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
