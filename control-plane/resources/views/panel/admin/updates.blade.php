@extends('layouts.panel')

@section('title', 'Aktualizacje')

@php
    use App\Domain\Updates\Updates;

    $latestSha = $latest['sha'] ?? null;
    $stateLabel = [
        'idle' => ['—', 'neutral'],
        'queued' => ['w kolejce', 'warning'],
        'stalled' => ['nie wystartowała', 'critical'],
        'running' => ['w toku', 'warning'],
        'done' => ['zakończona', 'ok'],
        'failed' => ['nieudana', 'critical'],
        'unreachable' => ['brak kontaktu', 'critical'],
    ];
    $versionPill = function (?string $sha) use ($latestSha) {
        if ($sha === null) return ['nieznana', 'neutral'];
        if ($latestSha === null) return ['?', 'neutral'];
        return $sha === $latestSha ? ['aktualna', 'ok'] : ['dostępna nowsza', 'warning'];
    };
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h1>Aktualizacje</h1>
            <p class="lede">
                Wersja panelu i agentów na węzłach. Aktualizację wykonują usługi systemowe na serwerach —
                panel tylko ją zleca, a źródło kodu jest ustawione lokalnie na każdym serwerze.
            </p>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('panel.admin.updates', ['refresh' => 1]) }}"><x-icon name="refresh" :size="16"/> Sprawdź teraz</a>
        </div>
    </div>

    @include('panel.admin._nav')

    <div class="card">
        <h3 class="card-title"><x-icon name="package" :size="16"/> Najnowsza wersja</h3>
        @if ($latest)
            <dl class="kv">
                <dt>Repozytorium</dt><dd class="mono">{{ config('virthub.update_repo') }} ({{ config('virthub.update_branch') }})</dd>
                <dt>Commit</dt>
                <dd><a class="mono" href="{{ $latest['url'] }}" target="_blank" rel="noopener">{{ Updates::short($latest['sha']) }}</a>
                    — {{ $latest['message'] }}</dd>
                @if ($latest['date'])
                    <dt>Data</dt><dd>{{ \Illuminate\Support\Carbon::parse($latest['date'])->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</dd>
                @endif
            </dl>
        @else
            <p class="muted">Nie udało się sprawdzić najnowszej wersji (brak połączenia z GitHubem albo limit zapytań). Spróbuj za kilka minut.</p>
        @endif
    </div>

    <div class="card" id="panel-card">
        @php [$vLabel, $vTone] = $versionPill($panelVersion); @endphp
        <h3 class="card-title">
            <x-icon name="dashboard" :size="16"/> Panel
            <span class="pill {{ $vTone }}" style="margin-left:auto">{{ $vLabel }}</span>
        </h3>
        <dl class="kv" style="margin-bottom:14px">
            <dt>Wersja</dt><dd class="mono">{{ Updates::short($panelVersion) }}</dd>
            <dt>Ostatnia aktualizacja</dt>
            <dd>
                @php [$sLabel, $sTone] = $stateLabel[$panelStatus['state'] ?? 'idle'] ?? ['—', 'neutral']; @endphp
                <span class="pill {{ $sTone }}" data-panel-state>{{ $sLabel }}</span>
                <span class="muted" data-panel-message>{{ $panelStatus['message'] ?? '' }}</span>
            </dd>
        </dl>

        @if ($panelEnabled)
            <form method="POST" action="{{ route('panel.admin.updates.panel') }}"
                  onsubmit="return confirm('Zaktualizować panel? Na kilka minut panel może przestać odpowiadać.')">
                @csrf
                <button class="btn btn-primary" type="submit"
                        @disabled(in_array($panelStatus['state'] ?? '', ['queued', 'running'], true))>
                    <x-icon name="refresh" :size="16"/> Aktualizuj panel
                </button>
            </form>
        @else
            <div class="alert alert-warning" style="margin:0">
                Zdalna aktualizacja panelu nie jest jeszcze włączona. Uruchom raz instalator panelu
                (<code>install-panel.sh</code>) w najnowszej wersji — założy usługę <code>virthub-panel-update</code>,
                a potem ten przycisk zadziała.
            </div>
        @endif

        @if (! empty($panelStatus['log']))
            <details class="form-block" style="margin:14px 0 0">
                <summary>Log ostatniej aktualizacji</summary>
                <pre class="secret" style="white-space:pre-wrap; max-height:320px; overflow:auto" data-panel-log>{{ $panelStatus['log'] }}</pre>
            </details>
        @endif
    </div>

    <div class="section-head">
        <h2>Węzły</h2>
        @if ($nodes->isNotEmpty())
            <form method="POST" action="{{ route('panel.admin.updates.nodes') }}"
                  onsubmit="return confirm('Zaktualizować wszystkie węzły z nieaktualnym agentem? Działające maszyny nie są restartowane.')">
                @csrf
                <button class="btn btn-primary" type="submit"><x-icon name="refresh" :size="16"/> Aktualizuj wszystkie nieaktualne</button>
            </form>
        @endif
    </div>

    <div class="card" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Węzeł</th><th>Rodzaj</th><th>Wersja agenta</th><th>Aktualizacja</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($nodes as $node)
                    @php
                        $build = $node->last_health['build'] ?? null;
                        [$nLabel, $nTone] = $versionPill($build);
                        $remote = (bool) ($node->last_health['remote_update'] ?? false);
                    @endphp
                    <tr data-node="{{ $node->id }}">
                        <td>
                            <strong>{{ $node->name }}</strong>
                            <div class="hint">{{ $node->isOnline() ? 'online' : 'offline' }}</div>
                        </td>
                        <td>{{ $node->virtualization->shortLabel() }}</td>
                        <td>
                            <span class="mono" data-build>{{ Updates::short($build) }}</span>
                            <span class="pill {{ $nTone }}" data-version-pill>{{ $nLabel }}</span>
                        </td>
                        <td>
                            <span class="pill neutral" data-state>—</span>
                            <div class="hint" data-message>
                                @unless ($remote)
                                    Zdalne aktualizacje wyłączone — zaktualizuj węzeł raz ręcznie.
                                @endunless
                            </div>
                        </td>
                        <td style="text-align:right">
                            <form method="POST" action="{{ route('panel.admin.updates.node', $node) }}" style="margin:0">
                                @csrf
                                <button class="btn btn-sm" type="submit" @disabled(! $node->isOnline())>Aktualizuj</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">Brak zarejestrowanych węzłów.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title"><x-icon name="terminal" :size="16"/> Pierwsza aktualizacja węzła</h3>
        <p style="margin-top:0">
            Węzły zainstalowane przed wprowadzeniem zdalnych aktualizacji trzeba zaktualizować raz ręcznie —
            to polecenie włącza też aktualizacje z tej strony:
        </p>
        <p class="secret">curl -sSL https://raw.githubusercontent.com/{{ config('virthub.update_repo') }}/{{ config('virthub.update_branch') }}/infra/update-node.sh | sudo bash</p>
    </div>

    @push('scripts')
        <script>
            // Stan aktualizacji na żywo — panelu i każdego węzła (pytamy agentów przez panel).
            (function () {
                const labels = @js($stateLabel);
                const short = (sha) => (sha ? sha.slice(0, 7) : '—');
                let timer = null;

                function paint(pill, state) {
                    const [text, tone] = labels[state] || ['—', 'neutral'];
                    pill.textContent = text;
                    pill.className = 'pill ' + tone;
                }

                async function refresh() {
                    let data;
                    try {
                        const res = await fetch(@js(route('panel.admin.updates.status')), {
                            headers: { Accept: 'application/json' }, credentials: 'same-origin',
                        });
                        if (!res.ok) throw new Error(res.status);
                        data = await res.json();
                    } catch (e) {
                        return; // panel właśnie się aktualizuje — spróbujemy za chwilę
                    }

                    let busy = ['queued', 'running'].includes(data.panel.state);
                    paint(document.querySelector('[data-panel-state]'), data.panel.state);
                    document.querySelector('[data-panel-message]').textContent = data.panel.message || '';
                    const log = document.querySelector('[data-panel-log]');
                    if (log && data.panel.log) log.textContent = data.panel.log;

                    for (const node of data.nodes) {
                        const row = document.querySelector(`[data-node="${node.id}"]`);
                        if (!row) continue;
                        paint(row.querySelector('[data-state]'), node.state);
                        row.querySelector('[data-message]').textContent = node.message || '';
                        if (node.build) {
                            row.querySelector('[data-build]').textContent = short(node.build);
                            const pill = row.querySelector('[data-version-pill]');
                            const current = data.latest && node.build === data.latest;
                            pill.textContent = data.latest ? (current ? 'aktualna' : 'dostępna nowsza') : '?';
                            pill.className = 'pill ' + (data.latest ? (current ? 'ok' : 'warning') : 'neutral');
                        }
                        busy = busy || ['queued', 'running'].includes(node.state);
                    }

                    clearTimeout(timer);
                    timer = setTimeout(refresh, busy ? 3000 : 20000);
                }

                refresh();
            })();
        </script>
    @endpush
@endsection
