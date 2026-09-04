<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ConsoleController;
use App\Http\Controllers\Web\PanelController;
use App\Http\Controllers\Web\SsoController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('panel.dashboard'));

// --- logowanie --------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Bilet z systemu rozliczeniowego — celowo poza grupą 'guest', bo klient może
// mieć już aktywną sesję na inne konto (np. wsparcie testujące zgłoszenie).
Route::get('/sso/{token}', SsoController::class)->name('sso.consume');

// --- panel ------------------------------------------------------------------
Route::middleware('auth')->prefix('panel')->name('panel.')->group(function () {
    Route::get('/', [PanelController::class, 'dashboard'])->name('dashboard');

    Route::get('/servers/new', [PanelController::class, 'createServer'])->name('servers.create');
    Route::post('/servers', [PanelController::class, 'storeServer'])->name('servers.store');
    Route::get('/servers/{server}', [PanelController::class, 'showServer'])->name('servers.show');
});

Route::get('/console/{token}', ConsoleController::class)
    ->middleware('auth')
    ->name('console.show');
