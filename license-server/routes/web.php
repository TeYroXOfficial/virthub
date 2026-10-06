<?php

use App\Http\Controllers\Admin\AddonController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\LicenseController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'admin'])->group(function () {
    Route::redirect('/', '/licenses');
    Route::resource('licenses', LicenseController::class)->except('show');
    Route::get('/addons', [AddonController::class, 'index'])->name('addons.index');
    Route::post('/addons', [AddonController::class, 'store'])->name('addons.store');
    Route::put('/addons/{addon}', [AddonController::class, 'update'])->name('addons.update');
    Route::post('/addons/{addon}/versions', [AddonController::class, 'upload'])->name('addons.upload');
    Route::post('/versions/{version}/toggle', [AddonController::class, 'toggleVersion'])->name('versions.toggle');
});
