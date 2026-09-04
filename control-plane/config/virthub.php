<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nazwa produktu
    |--------------------------------------------------------------------------
    | Panel jest białą etykietą — nazwa i kolor wiodący pochodzą stąd, a nie z
    | zaszytych wartości w widokach.
    */

    'brand' => env('VIRTHUB_BRAND', 'VirtHub'),

    /*
    |--------------------------------------------------------------------------
    | Proxy konsoli
    |--------------------------------------------------------------------------
    | Adres WebSocket proxy (noVNC/websockify) zestawiającego tunel do gniazda
    | VNC maszyny. Puste = konsola jeszcze niewdrożona; panel powie to wprost
    | zamiast pokazywać pustą ramkę.
    */

    'console_proxy_url' => env('VIRTHUB_CONSOLE_PROXY_URL'),

    /*
    |--------------------------------------------------------------------------
    | Kod agenta serwowany przy rejestracji hypervisora
    |--------------------------------------------------------------------------
    | Instalator uruchamiany na nowym węźle pobiera agenta stąd. Domyślnie
    | katalog obok panelu, zgodnie z układem monorepo; przy wdrożeniu samego
    | panelu wskaż ścieżkę, pod którą wgrałeś katalog `node-agent`.
    */

    'agent_source_path' => env('VIRTHUB_AGENT_SOURCE_PATH', base_path('../node-agent')),

    /*
    |--------------------------------------------------------------------------
    | Limity
    |--------------------------------------------------------------------------
    */

    'limits' => [
        // Ile maszyn może zamówić samodzielnie jeden klient. Zabezpieczenie
        // przed wyczerpaniem floty przez pojedyncze konto (albo skrypt).
        'servers_per_customer' => (int) env('VIRTHUB_SERVERS_PER_CUSTOMER', 10),

        // Retencja próbek telemetrii w dniach.
        'metrics_retention_days' => (int) env('VIRTHUB_METRICS_RETENTION_DAYS', 30),
    ],

];
