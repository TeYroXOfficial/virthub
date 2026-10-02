@extends('layouts.panel')

@section('title', __('Monitorowanie'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Monitorowanie') }}</h1>
            <p class="lede">{{ __('Obciążenie węzła na żywo i zużycie zasobów przez każdą maszynę, kontener i aplikację — odczytane prosto z systemu węzła.') }}</p>
        </div>
        @if ($nodes->isNotEmpty())
            <form method="GET" action="{{ route('panel.admin.monitoring') }}" class="actions">
                <select name="node" onchange="this.form.submit()" aria-label="{{ __('Węzeł') }}">
                    @foreach ($nodes as $n)
                        <option value="{{ $n->id }}" @selected($n->id === $node?->id)>{{ $n->name }}{{ $n->isOnline() ? '' : ' ('.__('offline').')' }}</option>
                    @endforeach
                </select>
                <span class="pill neutral" id="mon-status">{{ __('łączenie…') }}</span>
            </form>
        @endif
    </div>

    @if (! $node)
        <div class="card empty">
            <p>{{ __('Nie ma jeszcze zainstalowanego węzła do monitorowania.') }}</p>
            <a class="btn btn-primary" href="{{ route('panel.admin.hypervisors') }}">{{ __('Dodaj węzeł') }}</a>
        </div>
    @else
        <div class="alert alert-error" id="mon-error" hidden></div>

        <div id="monitoring" data-url="{{ route('panel.admin.monitoring.data', $node) }}">
            {{-- Kafelki: procesor, obciążenie, pamięć, temperatura procesora. --}}
            <div class="grid grid-4 mon-tiles">
                <div class="stat">
                    <div class="stat-label">{{ __('Procesor') }}</div>
                    <div class="stat-value" data-v="cpu.usage">—</div>
                    <div class="stat-sub" data-v="cpu.detail">&nbsp;</div>
                    <svg class="spark" data-spark="cpu" viewBox="0 0 120 28" preserveAspectRatio="none" aria-hidden="true"></svg>
                </div>
                <div class="stat">
                    <div class="stat-label">{{ __('Steal / iowait') }}</div>
                    <div class="stat-value" data-v="cpu.steal">—</div>
                    <div class="stat-sub" data-v="cpu.steal_detail">&nbsp;</div>
                    <svg class="spark" data-spark="steal" viewBox="0 0 120 28" preserveAspectRatio="none" aria-hidden="true"></svg>
                </div>
                <div class="stat">
                    <div class="stat-label">{{ __('Pamięć RAM') }}</div>
                    <div class="stat-value" data-v="mem.pct">—</div>
                    <div class="stat-sub" data-v="mem.detail">&nbsp;</div>
                    <svg class="spark" data-spark="mem" viewBox="0 0 120 28" preserveAspectRatio="none" aria-hidden="true"></svg>
                </div>
                <div class="stat">
                    <div class="stat-label">{{ __('Temperatura procesora') }}</div>
                    <div class="stat-value" data-v="temp.cpu">—</div>
                    <div class="stat-sub" data-v="host.load">&nbsp;</div>
                    <svg class="spark" data-spark="temp" viewBox="0 0 120 28" preserveAspectRatio="none" aria-hidden="true"></svg>
                </div>
            </div>

            <div class="grid grid-2 dash-section">
                <div class="card">
                    <h3 class="card-title"><x-icon name="servers" :size="16"/> {{ __('Procesor') }}
                        <span class="card-more" data-v="host.cpu"></span></h3>
                    <div class="stack-bar" data-v="cpu.stack" aria-hidden="true"></div>
                    <div class="legend-row">
                        <span><i class="dot" style="background:var(--series-1)"></i>{{ __('użytkownik') }}</span>
                        <span><i class="dot" style="background:var(--series-2)"></i>{{ __('system') }}</span>
                        <span><i class="dot warning"></i>{{ __('iowait') }}</span>
                        <span><i class="dot critical"></i>{{ __('steal') }}</span>
                    </div>
                    <div class="core-grid" data-v="cpu.cores"></div>
                    <dl class="kv" style="margin-top:14px" data-v="host.kv"></dl>
                </div>

                <div class="card">
                    <h3 class="card-title"><x-icon name="sliders" :size="16"/> {{ __('Pamięć i presja zasobów') }}</h3>
                    <dl class="kv" data-v="mem.kv"></dl>
                    <h3 class="card-title" style="margin-top:16px" title="{{ __('Procent czasu, w którym zadania czekały na zasób (Linux PSI, średnia z 10 s).') }}">{{ __('Presja (PSI, 10 s)') }}</h3>
                    <div data-v="pressure"></div>
                </div>
            </div>

            <div class="card flush dash-section">
                <div class="dash-head"><h3 class="card-title"><x-icon name="sliders" :size="16"/> {{ __('Temperatury') }}</h3></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Czujnik') }}</th><th>{{ __('Rodzaj') }}</th><th>{{ __('Urządzenie') }}</th><th>{{ __('Temperatura') }}</th><th>{{ __('Krytyczna') }}</th></tr></thead>
                        <tbody data-v="temps"></tbody>
                    </table>
                </div>
            </div>

            <div class="card flush dash-section">
                <div class="dash-head"><h3 class="card-title"><x-icon name="disc" :size="16"/> {{ __('Dyski i I/O') }}</h3></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Dysk') }}</th><th>{{ __('Rozmiar') }}</th><th>{{ __('Odczyt') }}</th><th>{{ __('Zapis') }}</th><th>{{ __('IOPS (odczyt / zapis)') }}</th><th>{{ __('Zajętość dysku') }}</th><th>{{ __('Czas operacji') }}</th></tr></thead>
                        <tbody data-v="disks"></tbody>
                    </table>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('System plików') }}</th><th>{{ __('Punkt montowania') }}</th><th>{{ __('Typ') }}</th><th>{{ __('Zajęte') }}</th><th>{{ __('I-węzły') }}</th></tr></thead>
                        <tbody data-v="filesystems"></tbody>
                    </table>
                </div>
            </div>

            <div class="card flush dash-section">
                <div class="dash-head"><h3 class="card-title"><x-icon name="network" :size="16"/> {{ __('Sieć') }}</h3></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Interfejs') }}</th><th>{{ __('Pobieranie') }}</th><th>{{ __('Wysyłanie') }}</th><th>{{ __('Odebrane łącznie') }}</th><th>{{ __('Wysłane łącznie') }}</th></tr></thead>
                        <tbody data-v="network"></tbody>
                    </table>
                </div>
            </div>

            <div class="card flush dash-section">
                <div class="dash-head">
                    <h3 class="card-title"><x-icon name="list" :size="16"/> {{ __('Usługi na węźle') }}</h3>
                    <span class="hint" style="margin:0">{{ __('Kliknij nagłówek, żeby posortować.') }}</span>
                </div>
                <div class="table-wrap">
                    <table class="sortable" data-table="services">
                        <thead><tr>
                            <th data-sort="name">{{ __('Usługa') }}</th>
                            <th data-sort="kind">{{ __('Rodzaj') }}</th>
                            <th data-sort="cpu_pct" title="{{ __('100% = jeden pełny rdzeń') }}">{{ __('Procesor') }}</th>
                            <th data-sort="cpu_wait_pct" title="{{ __('Czas, w którym procesy usługi czekały na wolny rdzeń (PSI) — odpowiednik steal widziany z wnętrza maszyny.') }}">{{ __('Czeka na CPU') }}</th>
                            <th data-sort="throttled_pct" title="{{ __('Czas zdławienia przez limit procesora z planu.') }}">{{ __('Limit CPU') }}</th>
                            <th data-sort="memory">{{ __('Pamięć') }}</th>
                            <th data-sort="read_bps">{{ __('Odczyt') }}</th>
                            <th data-sort="write_bps">{{ __('Zapis') }}</th>
                            <th data-sort="pids">{{ __('Procesy') }}</th>
                        </tr></thead>
                        <tbody data-v="services"></tbody>
                    </table>
                </div>
            </div>

            <div class="card flush dash-section">
                <div class="dash-head">
                    <h3 class="card-title"><x-icon name="terminal" :size="16"/> {{ __('Najbardziej obciążające procesy') }}</h3>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('PID') }}</th><th>{{ __('Proces') }}</th><th>{{ __('Procesor') }}</th><th>{{ __('Pamięć') }}</th><th>{{ __('Wątki') }}</th><th>{{ __('Należy do') }}</th></tr></thead>
                        <tbody data-v="processes"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
            window.VH_MONITOR = @js([
                'kinds' => ['kvm' => __('KVM'), 'lxc' => __('LXC'), 'app' => __('Aplikacja')],
                'categories' => ['cpu' => __('procesor'), 'disk' => __('dysk'), 'other' => __('inne')],
                'none' => __('brak'),
                'system' => __('system węzła'),
                'unknown' => __('nieznana w panelu'),
                'noTemps' => __('Węzeł nie udostępnia czujników temperatury (typowe dla serwerów wirtualnych). Temperatury dysków SATA wymagają modułu drivetemp.'),
                'noServices' => __('Na węźle nie działa teraz żadna usługa.'),
                'live' => __('na żywo'),
                'offline' => __('brak danych z węzła'),
                'cores' => __('rdzeni'),
                'used' => __('zajęte'),
                'available' => __('dostępne'),
                'cached' => __('podręczna'),
                'swap' => __('swap'),
                'dirty' => __('do zapisu'),
                'model' => __('Model'),
                'clock' => __('Zegar'),
                'kernel' => __('Jądro'),
                'uptime' => __('Czas działania'),
                'load' => __('obciążenie'),
                'days' => __('dni'),
                'cpu' => __('procesor'),
                'memory' => __('pamięć'),
                'io' => __('I/O'),
                'ssd' => __('SSD'),
                'hdd' => __('HDD'),
                'limit' => __('limit'),
            ]);
        </script>
        @push('scripts')
            <script src="{{ asset('js/monitoring.js') }}?v={{ @filemtime(public_path('js/monitoring.js')) }}"></script>
        @endpush
    @endif
@endsection
