@extends('layouts.panel')

@section('title', $server->hostname)

@section('content')
    <div class="page-header">
        <div>
            <h1 class="os-title">
                @if ($server->osFamily())
                    @include('panel.servers._os-badge', ['template' => (object) ['family' => $server->osFamily(), 'name' => $server->osLabel()]])
                @endif
                {{ $server->hostname }}
            </h1>
            <div class="meta-line">
                <span class="pill {{ $server->state->tone() }}">{{ $server->state->label() }}</span>
                <span>{{ $server->virtualization->label() }}</span>
                @if ($server->label) <span class="sep">·</span> <span>{{ $server->label }}</span> @endif
                @if ($server->primaryIp())
                    <span class="sep">·</span> <span class="mono">{{ $server->primaryIp()->address }}</span>
                @endif
                @if ($server->hypervisor && auth()->user()->isStaff())
                    <span class="sep">·</span> <span>{{ __('węzeł :name', ['name' => $server->hypervisor->name]) }}</span>
                @endif
            </div>
        </div>

        <div class="actions">
            @if ($server->acceptsCommands())
                @can('console', $server)
                <form method="POST" action="{{ route('panel.servers.console', $server) }}" target="_blank" style="margin:0">
                    @csrf
                    <button class="btn btn-primary" type="submit" @disabled(! $server->isRunning())
                            title="{{ $server->isRunning() ? __('Otwiera się w nowej karcie') : __('Uruchom maszynę, żeby otworzyć konsolę') }}">
                        <x-icon :name="$server->isContainer() ? 'terminal' : 'monitor'" :size="16"/>
                        {{ __('Konsola') }}
                    </button>
                </form>
                @endcan
                @can('power', $server)
                <div class="btn-group" id="power-controls">
                    <button class="btn" data-action="start" @disabled($server->isRunning()) title="{{ __('Uruchom') }}">
                        <x-icon name="play" :size="15"/> {{ __('Start') }}
                    </button>
                    <button class="btn" data-action="reboot" @disabled(! $server->isRunning()) title="{{ __('Restartuj') }}">
                        <x-icon name="refresh" :size="15"/> {{ __('Restart') }}
                    </button>
                    <button class="btn" data-action="stop" @disabled(! $server->isRunning()) title="{{ __('Zamknij system') }}">
                        <x-icon name="stop" :size="15"/> {{ __('Stop') }}
                    </button>
                    <button class="btn btn-danger" data-action="force-off" @disabled(! $server->isRunning())
                            title="{{ __('Odetnij zasilanie — niezapisane dane przepadną') }}">
                        <x-icon name="power" :size="15"/>
                    </button>
                </div>
                @endcan
                @if (auth()->user()->can('rebuild', $server) && $osChoices->isNotEmpty())
                    <button class="btn" type="button" data-open-reinstall title="{{ __('Postaw system od nowa') }}">
                        <x-icon name="refresh" :size="15"/> {{ __('Reinstaluj') }}
                    </button>
                @endif
            @endif
        </div>
    </div>

    @php
        $inProgress = $server->state->isTransitioning();
        $pendingJob = $inProgress ? null : $recentJobs->first(fn ($j) => ! $j->isFinished());
        $jobLabels = [
            'password' => __('zmiana hasła roota'), 'iso' => __('zmiana płyty ISO'), 'power' => __('operacja zasilania'),
            'network' => __('konfiguracja sieci'), 'snapshot' => __('tworzenie kopii'), 'restore' => __('przywracanie kopii'),
            'create' => __('utworzenie'), 'rebuild' => __('reinstalacja'), 'resize' => __('zmiana pakietu'), 'delete' => __('usunięcie'),
            'firewall' => __('zapora'),
        ];
    @endphp

    @if ($server->state_message && ! $inProgress)
        <div class="alert {{ $server->state->tone() === 'critical' ? 'alert-error' : 'alert-info' }}">
            {{ $server->state_message }}
        </div>
    @endif

    @if ($rootPassword)
        <div class="alert alert-info">
            <strong>{{ __('Hasło roota — zapisz je teraz.') }}</strong>
            <p style="margin:8px 0 0">
                @if ($inProgress)
                    {{ __('Hasło zadziała, gdy system się uruchomi. Widać je do końca operacji — potem zniknie z panelu.') }}
                @else
                    {{ __('Nie zobaczysz go ponownie.') }}
                @endif
                {{ __('Po zalogowaniu zmień je na własne (') }}<code>passwd</code>).
            </p>
            <p class="secret" style="margin-top:10px">{{ $rootPassword }}</p>
            <div class="btn-row">
                <button class="btn" type="button" onclick="
                    navigator.clipboard.writeText(@js($rootPassword));
                    this.textContent = 'Skopiowane';
                ">{{ __('Kopiuj hasło') }}</button>
            </div>
        </div>
    @endif

    @if ($server->isSuspended())
        <div class="alert alert-warning">
            <div>{{ __('Maszyna jest zawieszona (:suspension_reason). Sterowanie jest niedostępne.', ['suspension_reason' => $server->suspension_reason]) }}</div>
        </div>
    @endif

    @if ($pendingJob)
        <div class="alert alert-info job-watch" data-watch-job data-status-url="{{ route('panel.servers.status', $server) }}">
            <span class="spinner" aria-hidden="true"></span>
            <div>{{ __('Trwa: :action… Strona odświeży się po zakończeniu.', ['action' => $jobLabels[$pendingJob->action] ?? $pendingJob->action]) }}</div>
        </div>
    @endif

    @if ($inProgress)
        @include('panel.servers._progress')
        @can('purge', $server)
            <details class="form-block card" style="margin-top:16px">
                <summary>{{ __('Operacja utknęła? Opcje administratora') }}</summary>
                <form method="POST" action="{{ route('panel.servers.purge', $server) }}" style="margin-top:12px"
                      onsubmit="return confirm(@js(__('Usunąć wpis TYLKO z panelu? Panel nie skontaktuje się z węzłem.')))">
                    @csrf
                    <p class="muted" style="margin-top:0">
                        {{ __('Usuwa maszynę z panelu bez kontaktu z węzłem i zwalnia jej adresy IP. Jeśli maszyna istnieje na węźle, trzeba ją tam skasować ręcznie.') }}
                    </p>
                    <label class="check-line"><input type="checkbox" name="confirm" value="1" required> {{ __('Potwierdzam') }}</label>
                    <button class="btn btn-danger" type="submit" style="margin-top:10px">{{ __('Usuń tylko z panelu') }}</button>
                </form>
            </details>
        @endcan
    @else
        <nav class="tabs" role="tablist" aria-label="{{ __('Sekcje maszyny') }}">
            <button type="button" role="tab" data-tab="overview">{{ __('Przegląd') }}</button>
            <button type="button" role="tab" data-tab="stats">{{ __('Statystyki') }}</button>
            <button type="button" role="tab" data-tab="firewall-tab">{{ __('Zapora') }}</button>
            <button type="button" role="tab" data-tab="history">{{ __('Kopie i historia') }}</button>
            <button type="button" role="tab" data-tab="settings">{{ __('Ustawienia') }}</button>
        </nav>

        <div data-tab-panel="overview" role="tabpanel">
        <div class="grid grid-2">
            <div class="card">
                <h3 class="card-title"><x-icon name="node" :size="16"/> {{ __('Parametry') }}</h3>
                <dl class="kv">
                    <dt>{{ __('System') }}</dt>
                    <dd>
                        @if ($server->osLabel())
                            <span class="os-inline">
                                @include('panel.servers._os-badge', ['template' => (object) ['family' => $server->osFamily(), 'name' => $server->osLabel()]])
                                {{ $server->osLabel() }}
                            </span>
                            <div class="hint">{{ $server->osDetected() ? __('odczytany z maszyny') : __('z szablonu instalacji') }}</div>
                        @else
                            —
                        @endif
                    </dd>
                    <dt>{{ __('Procesor') }}</dt>
                    <dd>
                        <span class="num">{{ __(':vcpu vCPU', ['vcpu' => $server->vcpu]) }}</span>
                        @if ($server->cpu_limit_percent)
                            <span class="pill neutral plain" title="{{ __('Twardy limit czasu procesora dla całej maszyny') }}">{{ __('limit :percent%', ['percent' => $server->cpu_limit_percent]) }}</span>
                        @endif
                        @if ($cpu = $server->hypervisor?->cpuModel())
                            <div class="hint">{{ $cpu }}</div>
                        @endif
                    </dd>
                    <dt>{{ __('Pamięć') }}</dt><dd class="num">{{ __(':ram_mb GB', ['ram_mb' => round($server->ram_mb / 1024, 1)]) }}</dd>
                    <dt>{{ __('Dysk') }}</dt><dd class="num">{{ __(':disk_gb GB', ['disk_gb' => $server->disk_gb]) }}</dd>
                    <dt>{{ __('Transfer') }}</dt>
                    <dd id="traffic">
                        @php
                            $T = \App\Domain\Metrics\Traffic::class;
                            $pct = $traffic['percent'];
                        @endphp
                        <span class="num">{{ $T::human($traffic['used']) }}</span>
                        <span class="muted">{{ __('z :limit', ['limit' => $traffic['limit'] ? $T::human($traffic['limit']) : __('bez limitu')]) }}</span>
                        @if ($pct !== null)
                            <span class="num {{ $pct >= 100 ? 'text-critical' : ($pct >= 80 ? 'text-warn' : '') }}">· {{ str_replace('.', ',', $pct) }}%</span>
                            <div class="meter {{ $pct >= 80 ? 'hot' : '' }}" style="max-width:240px"><i style="width: {{ $pct }}%"></i></div>
                        @endif
                        <div class="hint">
                            {{ __('↓ :rx pobrane · ↑ :tx wysłane', ['rx' => $T::human($traffic['rx']), 'tx' => $T::human($traffic['tx'])]) }}
                            @if ($traffic['counting'] !== 'total')
                                {{ __('· do limitu liczy się tylko ruch :przychodzcy', ['przychodzcy' => $traffic['counting'] === 'out' ? __('wychodzący') : __('przychodzący')]) }}
                            @endif
                            <br>{{ __('Licznik zeruje się :y.', ['y' => \Carbon\Carbon::parse($traffic['resets_at'])->format('d.m.Y')]) }}
                        </div>
                    </dd>
                    <dt>{{ __('Szablon') }}</dt><dd>{{ $server->template?->name ?? '—' }}</dd>
                    <dt>{{ __('Pakiet') }}</dt><dd>{{ $server->package?->name ?? '—' }}</dd>
                    <dt>{{ __('Utworzona') }}</dt><dd>{{ $server->created_at->format('d.m.Y H:i') }}</dd>
                </dl>
            </div>

            <div class="card">
                <h3 class="card-title"><x-icon name="network" :size="16"/> {{ __('Adresy IP') }}</h3>
                @forelse ($server->ipAddresses as $ip)
                    <dl class="kv" style="margin-bottom:12px">
                        <dt>{{ $ip->is_primary ? __('Główny') : __('Dodatkowy') }}</dt>
                        <dd class="mono">
                            {{ $ip->address }}/{{ $ip->pool->prefix }}
                            @if ($ip->isNat()) <span class="pill warning">{{ __('NAT') }}</span> @endif
                        </dd>
                        <dt>{{ __('Brama') }}</dt><dd class="mono">{{ $ip->pool->gateway }}</dd>
                        @if ($ports = $ip->natPorts())
                            <dt>{{ __('Porty') }}</dt>
                            <dd>
                                <span class="mono">{{ $ports['from'] }}–{{ $ports['to'] }}</span>
                                <a href="#ports" class="muted">{{ __('mapowanie') }}</a>
                            </dd>
                        @endif
                        @if ($ip->rdns)
                            <dt>{{ __('rDNS') }}</dt><dd class="mono">{{ $ip->rdns }}</dd>
                        @endif
                    </dl>
                @empty
                    <p class="muted">{{ __('Maszyna nie ma jeszcze przypisanego adresu.') }}</p>
                @endforelse

                @if ($primary = $server->primaryIp())
                    @if ($primary->isNat())
                        <p class="hint">
                            {{ __('Maszyna stoi za NAT-em: wychodzi w świat adresem węzła, a z zewnątrz jest osiągalna wyłącznie przez porty powyżej.') }}
                            @if ($ports = $primary->natPorts())
                                @php $natHost = $primary->pool->nat_public_address ?: ($server->hypervisor?->hostname ?? __('adres-węzła')); @endphp
                                @if ($ports['rdp'])
                                    <br>{{ __('Pulpit zdalny (RDP):') }} <code>{{ $natHost }}:{{ $ports['rdp'] }}</code>
                                @endif
                                @if ($ports['ssh'])
                                    <br>{{ __('SSH / SFTP:') }} <code>ssh -p {{ $ports['ssh'] }} {{ ($server->osFamily() === 'windows' ? 'Administrator' : 'root').'@'.$natHost }}</code>
                                @endif
                            @endif
                        </p>
                    @else
                        <p class="hint">{{ __('Połączenie:') }} <code>ssh {{ 'root@'.$primary->address }}</code></p>
                    @endif
                @endif
            </div>
        </div>

        @include('panel.servers._ports')
        </div>

        <div data-tab-panel="stats" role="tabpanel" hidden>
    @php $charts = ['cpu' => __('Procesor'), 'ram' => __('Pamięć RAM'), 'disk' => __('Dysk I/O'), 'net' => __('Sieć')]; @endphp
    <section data-server-metrics
             data-live-url="{{ url("/api/v1/servers/{$server->id}/metrics/live") }}"
             data-history-url="{{ url("/api/v1/servers/{$server->id}/metrics") }}"
             data-running="{{ $server->isRunning() && $server->agent_uuid ? '1' : '0' }}">

        <div class="section-head" style="margin-top:0">
            <h2>{{ __('Zużycie na żywo') }}</h2>
            @if ($server->isRunning())
                <span class="pill warning" data-live-state>{{ __('łączenie…') }}</span>
            @endif
        </div>

        @if ($server->isRunning() && $server->agent_uuid)
            <div data-live>
                <div class="grid grid-4" style="margin-bottom:16px">
                    <div class="stat"><div class="stat-label">{{ __('Procesor') }}</div><div class="stat-value" data-now="cpu">—</div></div>
                    <div class="stat"><div class="stat-label">{{ __('Pamięć RAM') }}</div><div class="stat-value" data-now="ram">—</div></div>
                    <div class="stat"><div class="stat-label">{{ __('Dysk (odczyt / zapis)') }}</div><div class="stat-value" style="font-size:16px" data-now="disk">—</div></div>
                    <div class="stat"><div class="stat-label">{{ __('Sieć (pobieranie / wysyłanie)') }}</div><div class="stat-value" style="font-size:16px" data-now="net">—</div></div>
                </div>
                <div class="chart-grid">
                    @foreach ($charts as $key => $title)
                        <div class="card chart-card">
                            <div class="chart-head"><span class="chart-title">{{ $title }}</span><span class="chart-now">{{ __('ostatnie 5 minut') }}</span></div>
                            <div class="chart" data-chart="{{ $key }}"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="card empty">{{ __('Podgląd na żywo jest dostępny, gdy maszyna działa.') }}</div>
        @endif

        <div data-history>
            <div class="section-head">
                <h2>{{ __('Historia zużycia') }}</h2>
                <div class="segmented" role="group" aria-label="{{ __('Zakres historii') }}">
                    <button type="button" data-range="hour" aria-pressed="false">{{ __('Godzina') }}</button>
                    <button type="button" data-range="day" aria-pressed="true">{{ __('Doba') }}</button>
                    <button type="button" data-range="week" aria-pressed="false">{{ __('Tydzień') }}</button>
                </div>
            </div>
            <p class="hint" data-history-empty hidden style="margin-top:-4px">
                {{ __('Brak próbek w tym zakresie — panel zapisuje zużycie co minutę, gdy maszyna działa.') }}
            </p>
            <div class="chart-grid">
                @foreach ($charts as $key => $title)
                    <div class="card chart-card">
                        <div class="chart-head"><span class="chart-title">{{ $title }}</span><span class="chart-now">{{ __('średnie w przedziałach') }}</span></div>
                        <div class="chart" data-chart="{{ $key }}"></div>
                    </div>
                @endforeach
            </div>
            <details class="form-block card" style="margin-top:16px">
                <summary>{{ __('Dane w tabeli') }}</summary>
                <div class="table-wrap" style="max-height:360px; overflow:auto">
                    <table data-history-table>
                        <thead><tr><th>{{ __('Czas') }}</th><th>{{ __('CPU') }}</th><th>{{ __('RAM') }}</th><th>{{ __('Dysk odczyt') }}</th><th>{{ __('Dysk zapis') }}</th><th>{{ __('Sieć pobieranie') }}</th><th>{{ __('Sieć wysyłanie') }}</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </details>
        </div>
    </section>

    @push('head')
        <link rel="stylesheet" href="{{ asset('vendor/uplot/uPlot.min.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('vendor/uplot/uPlot.iife.min.js') }}"></script>
        <script src="{{ asset('js/server-metrics.js') }}?v={{ @filemtime(public_path('js/server-metrics.js')) }}"></script>
    @endpush

        </div>

        <div data-tab-panel="firewall-tab" role="tabpanel" hidden>
            @include('panel.servers._firewall')
        </div>

        <div data-tab-panel="history" role="tabpanel" hidden>

    <div class="card">
        <h3 class="card-title"><x-icon name="archive" :size="16"/> {{ __('Kopie') }}</h3>
        @forelse ($server->backups as $backup)
            <p style="margin:0 0 6px">
                <span class="mono">{{ $backup->name }}</span>
                <span class="pill {{ $backup->isRestorable() ? 'ok' : 'warning' }}">{{ $backup->status }}</span>
                <span class="muted">{{ $backup->created_at->diffForHumans() }}</span>
            </p>
        @empty
            <p class="muted">{{ __('Brak kopii. Snapshot wymaga zatrzymanej maszyny.') }}</p>
        @endforelse
    </div>

    <h2 class="section-head">{{ __('Historia operacji') }}</h2>
    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __('Operacja') }}</th><th>{{ __('Status') }}</th><th>{{ __('Kiedy') }}</th><th>{{ __('Szczegóły') }}</th></tr>
                </thead>
                <tbody>
                @forelse ($recentJobs as $job)
                    <tr>
                        <td>{{ ucfirst($jobLabels[$job->action] ?? $job->action) }}</td>
                        <td>
                            <span class="pill {{ match($job->status) {
                                'done' => 'ok', 'failed' => 'critical', default => 'warning' } }}">
                                {{ $job->status }}
                            </span>
                        </td>
                        <td>{{ $job->created_at->diffForHumans() }}</td>
                        <td class="muted">{{ $job->error ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">{{ __('Brak operacji.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

        </div>

        <div data-tab-panel="settings" role="tabpanel" hidden>
            @include('panel.servers._settings')
        </div>
    @endif

    @if (auth()->user()->can('rebuild', $server) && $osChoices->isNotEmpty() && $server->acceptsCommands())
        @include('panel.servers._reinstall-modal')
    @endif

    <script>
        // Sterowanie idzie przez API — ten sam endpoint, którego używa integracja
        // billingowa, więc nie ma dwóch ścieżek robiących to samo inaczej.
        document.getElementById('power-controls')?.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-action]');
            if (!button) return;

            button.disabled = true;
            const original = button.innerHTML;
            button.textContent = @js(__('Wysyłam…'));

            try {
                if (button.dataset.action === 'force-off'
                    && !confirm(@js(__('Odciąć zasilanie? Niezapisane dane w maszynie przepadną.')))) {
                    button.disabled = false;
                    button.innerHTML = original;
                    return;
                }

                const response = await fetch('{{ url("/api/v1/servers/{$server->id}/power") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: button.dataset.action }),
                });

                const payload = await response.json();

                if (!response.ok) {
                    alert(payload.message ?? __('Nie udało się wykonać operacji.'));
                    button.disabled = false;
                    button.innerHTML = original;
                    return;
                }

                location.reload();
            } catch (error) {
                alert(@js(__('Brak połączenia z panelem. Sprawdź sieć i spróbuj ponownie.')));
                button.disabled = false;
                button.innerHTML = original;
            }
        });
    </script>
    @push('scripts')
        <script src="{{ asset('js/os-picker.js') }}?v={{ @filemtime(public_path('js/os-picker.js')) }}"></script>
        <script src="{{ asset('js/server-page.js') }}?v={{ @filemtime(public_path('js/server-page.js')) }}"></script>
    @endpush
@endsection
