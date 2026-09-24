{{-- Ekran postępu tworzenia / reinstalacji — strona odświeża się sama po zakończeniu. --}}
@php
    use App\Enums\ServerState;
    [$title, $steps] = match ($server->state) {
        ServerState::Building => ['Tworzenie serwera', [
            ['Przydzielanie zasobów', 4], ['Kopiowanie obrazu systemu', 14],
            ['Konfiguracja sieci', 6], ['Uruchamianie serwera', 10],
        ]],
        ServerState::Rebuilding => ['Reinstalacja serwera', [
            ['Zatrzymywanie serwera', 4], ['Kopiowanie obrazu systemu', 14],
            ['Konfiguracja sieci i hasła', 6], ['Uruchamianie serwera', 10],
        ]],
        ServerState::Resizing => ['Zmiana pakietu', [
            ['Rezerwacja zasobów', 3], ['Zmiana procesora i pamięci', 6], ['Powiększanie dysku', 10],
        ]],
        default => ['Usuwanie serwera', [['Zatrzymywanie', 4], ['Usuwanie dysku', 8]]],
    };
    $job = $recentJobs->first();
@endphp
<section class="card progress-panel" id="progress"
         data-status-url="{{ route('panel.servers.status', $server) }}"
         data-elapsed="{{ $job ? (int) $job->created_at->diffInSeconds(now(), true) : 0 }}"
         data-steps='@json(array_column($steps, 1))'>
    <div class="progress-visual" aria-hidden="true">
        <div class="progress-orb"><x-icon name="servers" :size="30"/></div>
    </div>
    <div class="progress-main">
        <h2 data-progress-title>{{ $title }}…</h2>
        <p class="muted" data-progress-sub>
            To zwykle trwa od kilkudziesięciu sekund do kilku minut. Strona odświeży się sama.
        </p>
        <div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><i data-progress-bar></i></div>
        <ol class="progress-steps">
            @foreach ($steps as [$label])
                <li data-step><span class="step-dot"></span><span>{{ $label }}</span></li>
            @endforeach
        </ol>
    </div>
</section>
