{{-- Ekran postępu tworzenia / reinstalacji — kroki idą za etapem zgłaszanym przez węzeł,
     strona odświeża się sama po zakończeniu. --}}
@php
    use App\Enums\ServerState;
    // [klucz etapu na węźle, etykieta, typowy czas w s — gdy węzeł nie podaje etapu]
    [$title, $steps] = match ($server->state) {
        ServerState::Building => [__('Tworzenie serwera'), [
            ['prepare', __('Przygotowanie zasobów'), 4], ['image', __('Kopiowanie obrazu systemu'), 14],
            ['network', __('Konfiguracja sieci'), 6], ['boot', __('Uruchamianie serwera'), 10],
        ]],
        ServerState::Rebuilding => [__('Reinstalacja serwera'), [
            ['stop', __('Zatrzymywanie serwera'), 4], ['image', __('Kopiowanie obrazu systemu'), 14],
            ['network', __('Konfiguracja sieci i hasła'), 6], ['boot', __('Uruchamianie serwera'), 10],
        ]],
        ServerState::Resizing => [__('Zmiana pakietu'), [
            ['prepare', __('Rezerwacja zasobów'), 3], ['disk', __('Powiększanie dysku'), 8], ['resources', __('Zmiana procesora i pamięci'), 6],
        ]],
        ServerState::Migrating => ($recentJobs->first()?->payload['mode'] ?? 'cold') === 'live'
            ? [__('Migracja na żywo'), [
                ['export', __('Przygotowanie węzła docelowego'), 10], ['download', __('Przenoszenie pamięci i dysku'), 80], ['boot', __('Przełączenie na nowy węzeł'), 5],
            ]]
            : [__('Migracja na inny węzeł'), [
                ['export', __('Przygotowanie dysku na węźle źródłowym'), 20], ['download', __('Przenoszenie dysku'), 60], ['boot', __('Uruchamianie na nowym węźle'), 10],
            ]],
        default => [__('Usuwanie serwera'), [['stop', 'Zatrzymywanie', 4], ['disk', __('Usuwanie dysku'), 8]]],
    };
    $job = $recentJobs->first();
@endphp
<section class="card progress-panel" id="progress"
         data-status-url="{{ route('panel.servers.status', $server) }}"
         data-kind="{{ $job?->action }}"
         data-elapsed="{{ $job ? (int) $job->created_at->diffInSeconds(now(), true) : 0 }}"
         data-durations='@json(array_column($steps, 2))'>
    <div class="progress-visual" aria-hidden="true">
        <div class="progress-orb">
            <span class="orb-ring"></span>
            <span class="orb-icon" data-orb-icon><x-icon name="refresh" :size="34"/></span>
        </div>
    </div>
    <div class="progress-main">
        <h2 data-progress-title>{{ $title }}…</h2>
        <p class="muted" data-progress-sub>{{ __('Łączę się z węzłem…') }}</p>
        <div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i data-progress-bar></i></div>
        <ol class="progress-steps">
            @foreach ($steps as [$key, $label])
                <li data-step="{{ $key }}"><span class="step-dot"></span><span>{{ $label }}</span></li>
            @endforeach
        </ol>
        <p class="hint progress-foot">{{ __('Strona odświeży się sama, gdy serwer będzie gotowy.') }}</p>
        @if ($job?->action === 'migrate' && ! $job->isFinished() && auth()->user()->can('manageResources', $server))
            @if ($job->payload['cancel'] ?? false)
                <p class="hint">{{ __('Anulowanie zgłoszone — maszyna wróci na węzeł źródłowy po zakończeniu bieżącego kroku.') }}</p>
            @else
                <form method="POST" action="{{ route('panel.servers.migration', [$server, 'cancel']) }}" data-confirm="{{ __('Anulować migrację? Maszyna zostanie na węźle źródłowym.') }}">
                    @csrf <button class="btn btn-sm btn-danger" type="submit">{{ __('Anuluj migrację') }}</button>
                </form>
            @endif
        @endif
    </div>
</section>
