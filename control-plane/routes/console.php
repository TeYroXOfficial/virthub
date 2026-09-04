<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Harmonogram
|--------------------------------------------------------------------------
| Wszystko poniżej jest bezpieczne przy nakładaniu się uruchomień —
| withoutOverlapping chroni przed drugą kopią zadania, gdy hypervisor
| odpowiada wolno.
*/

// Heartbeat floty. Częściej niż co minutę nie ma sensu: dobór node'a i tak
// sprawdza pojemność w bazie pod blokadą.
Schedule::command('virthub:poll-hypervisors')
    ->everyMinute()
    ->withoutOverlapping();

// Siatka bezpieczeństwa na wypadek zgubionego callbacku od agenta.
Schedule::command('virthub:reconcile-jobs')
    ->everyMinute()
    ->withoutOverlapping();

// Telemetria co 5 minut — gęstsze próbkowanie zalewa bazę, a wykres i tak
// jest rysowany z uśrednień.
Schedule::command('virthub:collect-metrics')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Retencja telemetrii: 30 dni. Bez tego server_metrics rośnie w nieskończoność
// i po roku jest największą tabelą w systemie.
Schedule::call(function () {
    App\Models\ServerMetric::where('sampled_at', '<', now()->subDays(30))->delete();
})->daily()->name('prune-metrics');
