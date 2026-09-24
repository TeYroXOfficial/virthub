{{-- Ekran postępu tworzenia / reinstalacji — kroki idą za etapem zgłaszanym przez węzeł,
     strona odświeża się sama po zakończeniu. --}}
@php
    use App\Enums\ServerState;
    // [klucz etapu na węźle, etykieta, typowy czas w s — gdy węzeł nie podaje etapu]
    [$title, $steps] = match ($server->state) {
        ServerState::Building => ['Tworzenie serwera', [
            ['prepare', 'Przygotowanie zasobów', 4], ['image', 'Kopiowanie obrazu systemu', 14],
            ['network', 'Konfiguracja sieci', 6], ['boot', 'Uruchamianie serwera', 10],
        ]],
        ServerState::Rebuilding => ['Reinstalacja serwera', [
            ['stop', 'Zatrzymywanie serwera', 4], ['image', 'Kopiowanie obrazu systemu', 14],
            ['network', 'Konfiguracja sieci i hasła', 6], ['boot', 'Uruchamianie serwera', 10],
        ]],
        ServerState::Resizing => ['Zmiana pakietu', [
            ['prepare', 'Rezerwacja zasobów', 3], ['disk', 'Powiększanie dysku', 8], ['resources', 'Zmiana procesora i pamięci', 6],
        ]],
        default => ['Usuwanie serwera', [['stop', 'Zatrzymywanie', 4], ['disk', 'Usuwanie dysku', 8]]],
    };
    $job = $recentJobs->first();
@endphp
<section class="card progress-panel" id="progress"
         data-status-url="{{ route('panel.servers.status', $server) }}"
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
        <p class="muted" data-progress-sub>Łączę się z węzłem…</p>
        <div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i data-progress-bar></i></div>
        <ol class="progress-steps">
            @foreach ($steps as [$key, $label])
                <li data-step="{{ $key }}"><span class="step-dot"></span><span>{{ $label }}</span></li>
            @endforeach
        </ol>
        <p class="hint progress-foot">Strona odświeży się sama, gdy serwer będzie gotowy.</p>
    </div>
</section>
