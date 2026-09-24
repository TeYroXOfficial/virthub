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

// Telemetria co minutę — wykres godzinowy ma wtedy 60 punktów, a tygodniowy
// i tak uśrednia je do godzin.
Schedule::command('virthub:collect-metrics')
    ->everyMinute()
    ->withoutOverlapping();

// Retencja surowych próbek: 8 dni — tyle potrzebuje wykres tygodniowy. Bez
// tego server_metrics rośnie w nieskończoność.
Schedule::call(function () {
    App\Models\ServerMetric::where('sampled_at', '<', now()->subDays(8))->delete();
})->daily()->name('prune-metrics');
