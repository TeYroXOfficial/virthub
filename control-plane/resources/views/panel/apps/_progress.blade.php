{{-- Ekran postępu instalacji aplikacji, modpacka albo loadera — ten sam co przy
     maszynach (progress-panel.js); kroki idą za etapem zgłaszanym przez węzeł. --}}
@php
    $progressJob = $app->jobs()->whereIn('action', ['install', 'reinstall', 'modpack', 'loader'])->first();
    $action = $progressJob?->action ?? 'install';
    // [etap na węźle, etykieta, typowy czas w s — gdy węzeł nie podaje etapu]
    $installSteps = [
        ['installer', __('Pobieranie instalatora'), 15],
        ['script', __('Skrypt instalacyjny'), 60],
        ['image', __('Pobieranie obrazu aplikacji'), 25],
    ];
    [$title, $steps] = match ($action) {
        'reinstall' => [__('Reinstalacja aplikacji'), [['stop', __('Zatrzymywanie aplikacji'), 5], ...$installSteps]],
        'modpack' => [__('Instalacja modpacka :name', ['name' => $progressJob->payload['minecraft']['modpack']['name'] ?? '']), [
            ['prepare', __('Przygotowanie paczki'), 8], ['stop', __('Zatrzymywanie serwera'), 5],
            ['download', __('Pobieranie modów i konfiguracji'), 60], ['loader', __('Instalacja loadera'), 60],
        ]],
        'loader' => [__('Instalacja serwera :loader :version', ['loader' => ucfirst($progressJob->payload['loader'] ?? ''), 'version' => $progressJob->payload['mc'] ?? '']), [
            ['stop', __('Zatrzymywanie serwera'), 5], ['download', __('Pobieranie serwera i loadera'), 20], ['loader', __('Instalator loadera'), 60],
        ]],
        default => [__('Instalacja aplikacji'), $installSteps],
    };
@endphp
<section class="card progress-panel" id="progress" style="margin-bottom:16px"
         data-status-url="{{ route('panel.apps.status', $app) }}"
         data-elapsed="{{ $progressJob ? (int) $progressJob->created_at->diffInSeconds(now(), true) : 0 }}"
         data-durations='@json(array_column($steps, 2))'>
    <div class="progress-visual" aria-hidden="true">
        <div class="progress-orb">
            <span class="orb-ring"></span>
            <span class="orb-icon" data-orb-icon><x-icon name="refresh" :size="34"/></span>
        </div>
    </div>
    <div class="progress-main">
        <h2 data-progress-title>{{ trim($title) }}…</h2>
        <p class="muted" data-progress-sub>{{ __('Łączę się z węzłem…') }}</p>
        <div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i data-progress-bar></i></div>
        <ol class="progress-steps">
            @foreach ($steps as [$key, $label])
                <li data-step="{{ $key }}"><span class="step-dot"></span><span>{{ $label }}</span></li>
            @endforeach
        </ol>
        <p class="hint progress-foot">{{ __('Szczegóły na żywo widać w konsoli poniżej. Strona odświeży się sama po zakończeniu.') }}</p>
    </div>
</section>

@push('scripts')
    <script src="{{ asset('js/progress-panel.js') }}?v={{ @filemtime(public_path('js/progress-panel.js')) }}"></script>
    <script>
        (() => {
            const el = document.getElementById('progress');
            if (!el || !window.vhProgressPanel) return;
            window.vhProgressPanel(el, {
                aliases: { image: @js(in_array($action, ['modpack', 'loader'], true) ? 'loader' : 'image'), extract: 'download', done: @js(end($steps)[0]) },
                details: {
                    pending: @js($action === 'modpack' ? __('Panel przygotowuje paczkę — lista plików, loader i Java…') : __('Zlecenie czeka w kolejce panelu…')),
                },
                detailPrefix: { download: @js(__('Pobrano: ')) },
                doneTitle: @js(__('Gotowe! Instalacja zakończona.')),
                failTitle: @js(__('Instalacja nie powiodła się')),
                running: (data) => data.status === 'installing',
                failure: (data) => (data.job?.status === 'failed' || data.status === 'install_failed')
                    ? (data.job?.error || data.status_message || @js(__('Szczegóły pojawią się na stronie.')))
                    : null,
            });
        })();
    </script>
@endpush
