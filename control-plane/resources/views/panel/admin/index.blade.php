@extends('layouts.panel')

@section('title', __('Administracja'))

@section('content')
    <h1>{{ __('Administracja') }}</h1>
    <p class="lede">{{ __('Stan floty, maszyn i zasobów.') }}</p>

    @include('panel.admin._nav')

    <div class="grid grid-3">
        <div class="card">
            <h3>{{ __('Maszyny') }}</h3>
            <p style="font-size:28px; margin:0; font-variant-numeric: tabular-nums;">{{ $serversTotal }}</p>
            {{-- Warunek w wyrażeniu, nie dyrektywą: Blade nie kompiluje @if
                 przyklejonego do poprzedzającego słowa i zostawia go w treści. --}}
            <p class="hint">
                {{ __(':serversrunning działa:uwagi', ['serversrunning' => $serversRunning, 'uwagi' => $serversBroken ? __(', :count wymaga uwagi', ['count' => $serversBroken]) : '']) }}
            </p>
        </div>
        <div class="card">
            <h3>{{ __('Klienci') }}</h3>
            <p style="font-size:28px; margin:0; font-variant-numeric: tabular-nums;">{{ $customers }}</p>
            <p class="hint">{{ __('konta z rolą klienta') }}</p>
        </div>
        <div class="card">
            <h3>{{ __('Wolne adresy IP') }}</h3>
            <p style="font-size:28px; margin:0; font-variant-numeric: tabular-nums;">{{ $addressesFree }}</p>
            <p class="hint">{{ __('z :addressestotal w pulach', ['addressestotal' => $addressesTotal]) }}</p>
            @if ($addressesTotal > 0 && $addressesFree < 5)
                <p class="hint" style="color: var(--warn)">{{ __('Pula na wyczerpaniu — zaimportuj kolejną.') }}</p>
            @endif
        </div>
    </div>

    <h2>{{ __('Flota') }}</h2>
    @forelse ($hypervisors as $node)
        <div class="card">
            <h3>
                {{ $node->name }}
                <span class="pill {{ $node->isOnline() ? 'ok' : ($node->enrolled_at ? 'critical' : 'warning') }}"
                      style="float:right">
                    {{ $node->isOnline() ? __('online') : ($node->enrolled_at ? $node->status : __('czeka na instalację')) }}
                </span>
            </h3>

            @if ($node->enrolled_at === null)
                <p class="muted">
                    {{ __('Węzeł dodany, ale agent nie został jeszcze zainstalowany. Wygeneruj polecenie instalacyjne na') }}
                    <a href="{{ route('panel.admin.hypervisors') }}">{{ __('liście hypervisorów') }}</a>.
                </p>
            @else
                <dl class="kv">
                    <dt>{{ __('Rodzaj') }}</dt><dd>{{ $node->virtualization->label() }}</dd>
                    <dt>{{ __('Adres') }}</dt><dd class="mono">{{ $node->agent_url }}</dd>
                    <dt>{{ __('Maszyny') }}</dt><dd class="num">{{ $node->servers_count }}</dd>
                    <dt>{{ __('vCPU') }}</dt><dd class="num">{{ $node->cpu_cores_used }} / {{ $node->cpu_cores_total }}</dd>
                    <dt>{{ __('RAM') }}</dt><dd class="num">{{ __(':ram_mb_used / :ram_mb_total GB', ['ram_mb_used' => round($node->ram_mb_used / 1024), 'ram_mb_total' => round($node->ram_mb_total / 1024)]) }}</dd>
                    <dt>{{ __('Dysk') }}</dt><dd class="num">{{ __(':disk_gb_used / :disk_gb_total GB', ['disk_gb_used' => $node->disk_gb_used, 'disk_gb_total' => $node->disk_gb_total]) }}</dd>
                    <dt>{{ __('Ostatni kontakt') }}</dt><dd>{{ $node->last_seen_at?->diffForHumans() ?? __('nigdy') }}</dd>
                </dl>
                @php $util = $node->utilisationPercent(); @endphp
                <div class="meter {{ $util > 80 ? 'hot' : '' }}"><i style="width: {{ min(100, $util) }}%"></i></div>
                <div class="hint">{{ __('Zajętość :util%', ['util' => $util]) }}</div>
            @endif
        </div>
    @empty
        <div class="card empty">
            <p>{{ __('Nie masz jeszcze żadnego hypervisora. Bez niego nie da się utworzyć maszyny.') }}</p>
            <a class="btn btn-primary" href="{{ route('panel.admin.hypervisors') }}">{{ __('Dodaj pierwszy węzeł') }}</a>
        </div>
    @endforelse

    <h2>{{ __('Ostatnie zdarzenia') }}</h2>
    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Zdarzenie') }}</th><th>{{ __('Kto') }}</th><th>{{ __('Czego dotyczy') }}</th><th>{{ __('Kiedy') }}</th></tr></thead>
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
                    <tr><td colspan="4" class="muted">{{ __('Brak zdarzeń.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
