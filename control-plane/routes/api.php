<?php

use App\Http\Controllers\Admin\CatalogController as AdminCatalogController;
use App\Http\Controllers\Admin\HypervisorController;
use App\Http\Controllers\Admin\HypervisorGroupController;
use App\Http\Controllers\Admin\IpPoolController;
use App\Http\Controllers\Admin\ServerAdminController;
use App\Http\Controllers\Api\BillingIntegrationController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\FirewallController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\SnapshotController;
use App\Http\Controllers\Internal\AgentCallbackController;
use App\Http\Controllers\Internal\ConsoleRedeemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API panelu
|--------------------------------------------------------------------------
| Trzy grupy odbiorców, trzy różne sposoby uwierzytelnienia:
|
|   /api/v1          – klient i personel, token Sanctum wystawiony po zalogowaniu
|   /api/v1/billing  – system rozliczeniowy, token z uprawnieniem "billing"
|   /api/internal    – agenci hypervisorów, podpis HMAC (bez sesji i bez tokenu)
*/

Route::prefix('v1')->group(function () {

    // --- klient ------------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', fn (Illuminate\Http\Request $request) => response()->json([
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
            'role' => $request->user()->role,
        ]));

        Route::get('/packages', [CatalogController::class, 'packages']);
        Route::get('/os-templates', [CatalogController::class, 'templates']);

        Route::apiResource('servers', ServerController::class)
            ->only(['index', 'store', 'show', 'destroy']);

        Route::prefix('servers/{server}')->group(function () {
            Route::post('/power', [ServerController::class, 'power']);
            Route::post('/rebuild', [ServerController::class, 'rebuild']);
            Route::post('/resize', [ServerController::class, 'resize']);
            Route::get('/stats', [ServerController::class, 'stats']);
            Route::get('/credentials', [ServerController::class, 'credentials']);
            Route::post('/console-token', [ServerController::class, 'consoleToken']);

            Route::get('/firewall', [FirewallController::class, 'index']);
            Route::post('/firewall', [FirewallController::class, 'store']);
            Route::delete('/firewall/{rule}', [FirewallController::class, 'destroy']);

            Route::get('/snapshots', [SnapshotController::class, 'index']);
            Route::post('/snapshots', [SnapshotController::class, 'store']);
            Route::post('/snapshots/{backup}/restore', [SnapshotController::class, 'restore']);
            Route::delete('/snapshots/{backup}', [SnapshotController::class, 'destroy']);
        });
    });

    // --- administracja -----------------------------------------------------
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        Route::apiResource('hypervisors', HypervisorController::class);
        Route::get('hypervisors/{hypervisor}/health', [HypervisorController::class, 'health']);
        Route::post('hypervisors/{hypervisor}/recalculate', [HypervisorController::class, 'recalculate']);
        Route::post('hypervisors/{hypervisor}/rotate-token', [HypervisorController::class, 'rotateToken']);

        Route::get('ip-pools', [IpPoolController::class, 'index']);
        Route::post('ip-pools', [IpPoolController::class, 'store']);
        Route::delete('ip-pools/{pool}', [IpPoolController::class, 'destroy']);
        Route::get('ip-pools/{pool}/addresses', [IpPoolController::class, 'addresses']);
        Route::apiResource('hypervisor-groups', HypervisorGroupController::class)
            ->parameters(['hypervisor-groups' => 'group'])
            ->except('show');
        Route::post('ip-addresses/{address}/release', [IpPoolController::class, 'release']);
        Route::put('ip-addresses/{address}/rdns', [IpPoolController::class, 'updateRdns']);

        Route::post('packages', [AdminCatalogController::class, 'storePackage']);
        Route::put('packages/{package}', [AdminCatalogController::class, 'updatePackage']);
        Route::delete('packages/{package}', [AdminCatalogController::class, 'destroyPackage']);

        Route::post('os-templates', [AdminCatalogController::class, 'storeTemplate']);
        Route::put('os-templates/{template}', [AdminCatalogController::class, 'updateTemplate']);
        Route::delete('os-templates/{template}', [AdminCatalogController::class, 'destroyTemplate']);

        Route::get('audit-logs', [ServerAdminController::class, 'auditLogs']);
    });

    // Wsparcie widzi wszystkie maszyny i historię operacji, ale nie zmienia oferty.
    Route::middleware(['auth:sanctum', 'admin:staff'])->prefix('admin')->group(function () {
        Route::get('servers', [ServerAdminController::class, 'index']);
        Route::get('servers/{server}/jobs', [ServerAdminController::class, 'jobs']);
        Route::post('servers/{server}/suspend', [ServerAdminController::class, 'suspend']);
        Route::post('servers/{server}/unsuspend', [ServerAdminController::class, 'unsuspend']);
    });

    // --- system rozliczeniowy ----------------------------------------------
    Route::middleware(['auth:sanctum', 'abilities:billing'])->prefix('billing')->group(function () {
        Route::post('/provision', [BillingIntegrationController::class, 'provision']);
        Route::post('/sso-token', [BillingIntegrationController::class, 'ssoToken']);
        Route::post('/services/{reference}/suspend', [BillingIntegrationController::class, 'suspend']);
        Route::post('/services/{reference}/unsuspend', [BillingIntegrationController::class, 'unsuspend']);
        Route::post('/services/{reference}/terminate', [BillingIntegrationController::class, 'terminate']);
    });
});

// --- agenci hypervisorów ---------------------------------------------------
// Bez middleware uwierzytelniającego: agent nie ma konta w panelu, a jego
// tożsamość potwierdza podpis HMAC weryfikowany w kontrolerze.
Route::post('/internal/agent/job-result', AgentCallbackController::class)
    ->name('internal.agent.job-result');

// Przekaźnik konsoli (console-proxy/) wymienia jednorazową sesję na parametry
// połączenia z agentem. Uwierzytelnienie wspólnym sekretem w kontrolerze.
Route::post('/internal/console/{session}', ConsoleRedeemController::class)
    ->where('session', '[A-Za-z0-9]{32,128}')
    ->middleware('throttle:60,1')
    ->name('internal.console.redeem');
