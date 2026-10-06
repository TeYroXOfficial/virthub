<?php

use App\Http\Controllers\Api\LicenseApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    Route::get('/public-key', [LicenseApiController::class, 'publicKey']);
    Route::post('/license/verify', [LicenseApiController::class, 'verify']);
    Route::post('/addons', [LicenseApiController::class, 'catalog']);
    Route::post('/addons/{slug}/download', [LicenseApiController::class, 'download'])->where('slug', '[a-z][a-z0-9-]{1,39}')->middleware('throttle:20,1');
});
