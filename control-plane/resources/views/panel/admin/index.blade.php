@extends('layouts.panel')

@section('title', __('Administracja'))

@php
    $taskLabels = [
        'server' => __('Maszyna'), 'app' => __('Aplikacja'), 'template' => __('Szablon'),
    ];
    $taskTone = ['queued' => 'neutral', 'running' => 'warning', 'downloading' => 'warning', 'failed' => 'critical'];
    $meter = fn (?int $v) => $v === null ? null : ['value' => $v, 'tone' => $v >= 90 ? 'critical' : ($v >= 75 ? 'hot' : '')];
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Administracja') }}</h1>
            <p class="lede">{{ __('Stan usług, węzłów i systemu w jednym miejscu.') }}</p>
        </div>
    </div>

    @include('panel.admin._nav')

    @if (! $system['mail'] && auth()->user()->hasPermission('admin.settings'))
        <div class="alert alert-warning">
            <strong>{{ __('Poczta nie jest skonfigurowana.') }}</strong>
            {{ __('Link „Nie pamiętasz hasła?” nie dotrze do klientów — wiadomości trafiają tylko do logu panelu.') }}
            <a href="{{ route('panel.admin.mail') }}">{{ __('Ustaw serwer SMTP') }}</a>
        </div>
    @endif

    {{-- Kafelki stanu: każda pozycja prowadzi do przefiltrowanej listy. --}}
    <div class="grid dash-tiles">
        @if ($canServers)
            <div class="card dash-card">
                <h3 class="card-title"><x-icon name="servers" :size="16"/> {{ __('Maszyny') }}
                    <a class="card-more" href="{{ route('panel.admin.servers') }}">{{ $servers['total'] }}</a></h3>
                <ul class="status-list">
                    <li><span><i class="dot ok"></i>{{ __('Działające') }}</span><b>{{ $servers['running'] }}</b></li>
                    <li><span><i class="dot neutral"></i>{{ __('Zatrzymane') }}</span><b>{{ $servers['stopped'] }}</b></li>
                    <li><span><i class="dot info"></i>{{ __('W trakcie operacji') }}</span><b>{{ $servers['busy'] }}</b></li>
                    <li><a href="{{ route('panel.admin.services', ['kind' => null, 'status' => 'suspended']) }}"><i class="dot warning"></i>{{ __('Zawieszone') }}</a><b>{{ $servers['suspended'] }}</b></li>
                    <li><a href="{{ route('panel.admin.services', ['status' => 'problem']) }}"><i class="dot critical"></i>{{ __('Błąd') }}</a><b>{{ $servers['error'] }}</b></li>
                </ul>
            </div>
        @endif

        @if ($canApps)
            <div class="card dash-card">
                <h3 class="card-title"><x-icon name="gamepad" :size="16"/> {{ __('Aplikacje') }}
                    <a class="card-more" href="{{ route('panel.admin.apps') }}">{{ $apps['total'] }}</a></h3>
                <ul class="status-list">
                    <li><span><i class="dot ok"></i>{{ __('Gotowe') }}</span><b>{{ $apps['ready'] }}</b></li>
                    <li><span><i class="dot info"></i>{{ __('Instalacja') }}</span><b>{{ $apps['installing'] }}</b></li>
                    <li><a href="{{ route('panel.admin.services', ['kind' => 'app', 'status' => 'problem']) }}"><i class="dot critical"></i>{{ __('Błąd instalacji') }}</a><b>{{ $apps['failed'] }}</b></li>
                    <li><a href="{{ route('panel.admin.services', ['kind' => 'app', 'status' => 'suspended']) }}"><i class="dot warning"></i>{{ __('Zawieszone') }}</a><b>{{ $apps['suspended'] }}</b></li>
                </ul>
            </div>
        @endif

        <div class="card dash-card">
            <h3 class="card-title"><x-icon name="shield" :size="16"/> {{ __('System') }}
                @if ($system['version'])
                    <span class="card-more mono">{{ $system['version'] }}</span>
                @endif
            </h3>
            <ul class="status-list">
                <li><span>{{ __('Wersja panelu') }}</span>
                    @php $canUpdates = auth()->user()->hasPermission('admin.updates'); @endphp
                    @if ($system['up_to_date'] === true)
                        <span class="pill ok">{{ __('aktualna') }}</span>
                    @elseif ($system['up_to_date'] === false)
                        <a class="pill warning" @if ($canUpdates) href="{{ route('panel.admin.updates') }}" @endif>{{ __('dostępna nowsza') }}</a>
                    @elseif ($canUpdates)
                        <a class="pill neutral" href="{{ route('panel.admin.updates', ['refresh' => 1]) }}">{{ __('sprawdź') }}</a>
                    @else
                        <span class="pill neutral">?</span>
                    @endif
                </li>
                <li><span>{{ __('Węzły online') }}</span>
                    <span class="pill {{ $system['nodes_total'] && $system['nodes_online'] === $system['nodes_total'] ? 'ok' : ($system['nodes_total'] ? 'critical' : 'neutral') }}">{{ $system['nodes_online'] }} / {{ $system['nodes_total'] }}</span></li>
                <li><span>{{ __('Poczta (SMTP)') }}</span>
                    <span class="pill {{ $system['mail'] ? 'ok' : 'warning' }}">{{ $system['mail'] ? __('włączona') : __('wyłączona') }}</span></li>
                <li><span>{{ __('Nieudane zadania (24 h)') }}</span>
                    <span class="pill {{ $system['failed_jobs'] ? 'critical' : 'ok' }}">{{ $system['failed_jobs'] }}</span></li>
                <li><span>{{ __('Klienci') }}</span><b>{{ $system['customers'] }}</b></li>
            </ul>
        </div>

        <div class="card dash-card">
            <h3 class="card-title"><x-icon name="network" :size="16"/> {{ __('Adresy IPv4') }}
                <a class="card-more" href="{{ route('panel.admin.ip-pools') }}">{{ $ipv4['total'] }}</a></h3>
            <ul class="status-list">
                <li><span><i class="dot ok"></i>{{ __('Wolne') }}</span><b>{{ $ipv4['free'] }}</b></li>
                <li><span><i class="dot info"></i>{{ __('Przydzielone') }}</span><b>{{ $ipv4['used'] }}</b></li>
                <li><span><i class="dot neutral"></i>{{ __('Zarezerwowane') }}</span><b>{{ $ipv4['reserved'] }}</b></li>
            </ul>
            @if ($ipv4['total'] > 0 && $ipv4['free'] < 5)
                <p class="hint" style="color: var(--warn); margin:8px 0 0">{{ __('Pula na wyczerpaniu — zaimportuj kolejną.') }}</p>
            @endif
        </div>
    </div>

    {{-- Zasoby węzłów: obciążenie hosta z raportu agenta i przydział zasobów. --}}
    <div class="card flush dash-section">
        <div class="dash-head">
            <h3 class="card-title"><x-icon name="node" :size="16"/> {{ __('Zasoby węzłów') }}</h3>
            @if ($canNodes)
                <a class="btn btn-sm" href="{{ route('panel.admin.hypervisors') }}">{{ __('Wszystkie węzły') }}</a>
            @endif
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>{{ __('Węzeł') }}</th><th>{{ __('Stan') }}</th>
                    <th>{{ __('Procesor') }}</th><th>{{ __('Pamięć') }}</th><th>{{ __('Dysk') }}</th>
                    <th title="{{ __('Zasoby przydzielone maszynom względem pojemności węzła') }}">{{ __('Przydział') }}</th>
                    <th>{{ __('Usługi') }}</th><th>{{ __('Ostatni kontakt') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($nodes as $row)
                    @php $node = $row['node']; @endphp
                    <tr>
                        <td>
                            @if ($canNodes)
                                <a href="{{ route('panel.admin.hypervisors.show', $node) }}"><strong>{{ $node->name }}</strong></a>
                            @else
                                <strong>{{ $node->name }}</strong>
                            @endif
                            <div class="hint">{{ $node->virtualization->label() }}</div>
                        </td>
                        <td>
                            @if (! $row['enrolled'])
                                <span class="pill warning">{{ __('czeka na instalację') }}</span>
                            @else
                                <span class="pill {{ $row['online'] ? 'ok' : 'critical' }}">{{ $row['online'] ? __('online') : $node->status }}</span>
                            @endif
                        </td>
                        @foreach (['cpu', 'mem', 'disk', 'allocated'] as $metric)
                            @php $m = $meter($row[$metric]); @endphp
                            <td class="metric-cell">
                                @if ($m)
                                    <span class="num">{{ $m['value'] }}%</span>
                                    <div class="meter {{ $m['tone'] }}"><i style="width: {{ min(100, $m['value']) }}%"></i></div>
                                    @if ($metric === 'cpu' && $row['load'] !== null)
                                        <div class="hint">{{ __('load :load', ['load' => number_format((float) $row['load'], 2)]) }}</div>
                                    @endif
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="num">{{ __(':servers maszyn · :apps apl.', ['servers' => $row['servers'], 'apps' => $row['apps']]) }}</td>
                        <td class="muted">{{ $node->last_seen_at?->diffForHumans() ?? __('nigdy') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted" style="text-align:center; padding:24px">
                        {{ __('Nie masz jeszcze żadnego węzła. Bez niego nie da się utworzyć usługi.') }}
                        @if ($canNodes) <a href="{{ route('panel.admin.hypervisors') }}">{{ __('Dodaj pierwszy węzeł') }}</a> @endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-2 dash-section">
        <div class="card">
            <h3 class="card-title"><x-icon name="clock" :size="16"/> {{ __('Zadania') }}</h3>
            @php $taskRows = $tasks['running']->concat($tasks['failed']); @endphp
            @if ($taskRows->isEmpty())
                <p class="empty-note">{{ __('Nic się teraz nie dzieje i nic nie zawiodło w ostatniej dobie.') }}</p>
            @else
                <ul class="task-list">
                    @foreach ($taskRows as $task)
                        <li>
                            <div>
                                <span class="pill {{ $taskTone[$task['status']] ?? 'neutral' }} plain">{{ $taskLabels[$task['kind']] }}</span>
                                <span class="mono">{{ $task['action'] }}</span>
                                @if ($task['url'])
                                    <a href="{{ $task['url'] }}">{{ $task['subject'] ?? '—' }}</a>
                                @else
                                    {{ $task['subject'] ?? '—' }}
                                @endif
                                @if ($task['status'] === 'failed' && $task['error'])
                                    <div class="hint" style="color:var(--critical)">{{ \Illuminate\Support\Str::limit($task['error'], 140) }}</div>
                                @endif
                            </div>
                            <span class="muted nowrap">{{ $task['at']?->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="users" :size="16"/> {{ __('Aktywni użytkownicy') }}
                <span class="card-more muted">{{ __('15 min') }}</span></h3>
            @if ($activeUsers === null)
                <p class="empty-note">{{ __('Niedostępne — sesje nie są trzymane w bazie danych albo nie masz dostępu do użytkowników.') }}</p>
            @elseif ($activeUsers->isEmpty())
                <p class="empty-note">{{ __('Nikt nie był aktywny w ostatnich 15 minutach.') }}</p>
            @else
                <div class="user-chips">
                    @foreach ($activeUsers as $active)
                        @php $u = $active['user']; @endphp
                        <a class="user-chip" href="{{ route('panel.admin.users.edit', $u) }}">
                            @if ($avatar = $u->avatarUrl())
                                <img class="avatar" src="{{ $avatar }}" alt="">
                            @else
                                <span class="avatar">{{ mb_substr($u->name ?: $u->email, 0, 1) }}</span>
                            @endif
                            <span>
                                <strong>{{ $u->name ?: $u->email }}</strong>
                                <span class="hint mono">{{ $active['ip'] ?? '?' }} · {{ $active['at']->diffForHumans() }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @if ($canServers || $canApps)
        <div class="card flush dash-section">
            <div class="dash-head">
                <h3 class="card-title"><x-icon name="list" :size="16"/> {{ __('Ostatnie usługi') }}</h3>
                <div class="segmented" role="group" aria-label="{{ __('Liczba pozycji') }}">
                    @foreach (\App\Domain\Admin\Dashboard::RECENT_SIZES as $size)
                        <a href="{{ route('panel.admin.index', ['recent' => $size]) }}" @if ($size === $recent) aria-current="true" @endif>{{ $size }}</a>
                    @endforeach
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>{{ __('Usługa') }}</th><th>{{ __('Rodzaj') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Adres') }}</th><th>{{ __('Węzeł') }}</th><th>{{ __('Zasoby') }}</th><th>{{ __('Utworzona') }}</th></tr>
                    </thead>
                    <tbody>
                    @forelse ($recentServices as $item)
                        @include('panel.admin._service-row')
                    @empty
                        <tr><td colspan="8" class="muted" style="text-align:center; padding:24px">{{ __('Nie ma jeszcze żadnych usług.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if (auth()->user()->isAdmin())
        <div class="card flush dash-section">
            <div class="dash-head">
                <h3 class="card-title"><x-icon name="archive" :size="16"/> {{ __('Ostatnie zdarzenia') }}</h3>
                <a class="btn btn-sm" href="{{ route('panel.admin.logs') }}">{{ __('Pełny dziennik') }}</a>
            </div>
            @include('panel.admin._log-table', ['logs' => $recentLogs])
        </div>
    @endif
@endsection
