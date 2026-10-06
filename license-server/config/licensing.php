<?php

return [
    // Klucz prywatny Ed25519 (base64, 64 bajty) — `php artisan license:keygen`.
    // Tylko na tym serwerze; panele dostają klucz publiczny.
    'signing_key' => env('LICENSE_SIGNING_KEY'),

    // Gdzie trzymane są paczki addonów (poza katalogiem publicznym).
    'packages_disk' => env('LICENSE_PACKAGES_DISK', 'local'),
];
