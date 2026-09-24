<?php

use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\Web\AdminController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ConsoleController;
use App\Http\Controllers\Web\FirewallController;
use App\Http\Controllers\Web\IsoController;
use App\Http\Controllers\Web\ServerActionsController;
use App\Http\Controllers\Web\PanelController;
use App\Http\Controllers\Web\SsoController;
use App\Http\Controllers\Web\UpdatesController;
use App\Http\Controllers\Web\UsersController;
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
Route::middleware(['auth', 'not-suspended'])->prefix('panel')->name('panel.')->group(function () {
    Route::get('/', [PanelController::class, 'dashboard'])->name('dashboard');

    Route::get('/servers/new', [PanelController::class, 'createServer'])->name('servers.create');
    Route::post('/servers', [PanelController::class, 'storeServer'])->name('servers.store');
    Route::get('/servers/{server}', [PanelController::class, 'showServer'])->name('servers.show');
    Route::post('/servers/{server}/console', [ConsoleController::class, 'open'])->name('servers.console');
    Route::post('/servers/{server}/rebuild', [ServerActionsController::class, 'rebuild'])->name('servers.rebuild');
    Route::post('/servers/{server}/iso', [ServerActionsController::class, 'iso'])->name('servers.iso');
    Route::post('/servers/{server}/password', [ServerActionsController::class, 'resetPassword'])->name('servers.password');
    Route::get('/servers/{server}/status', [ServerActionsController::class, 'status'])->name('servers.status');
    Route::delete('/servers/{server}', [ServerActionsController::class, 'destroy'])->name('servers.destroy');
    Route::post('/servers/{server}/purge', [ServerActionsController::class, 'purge'])->name('servers.purge');
    Route::post('/servers/{server}/detect-os', [ServerActionsController::class, 'detectOs'])->name('servers.detect-os');

    Route::prefix('/servers/{server}/firewall')->name('servers.firewall.')->group(function () {
        Route::post('/policy', [FirewallController::class, 'policy'])->name('policy');
        Route::post('/rules', [FirewallController::class, 'store'])->name('store');
        Route::post('/presets/{preset}', [FirewallController::class, 'preset'])->name('preset');
        Route::delete('/rules/{rule}', [FirewallController::class, 'destroy'])->name('destroy');
        Route::post('/rules/{rule}/toggle', [FirewallController::class, 'toggle'])->name('toggle');
        Route::post('/rules/{rule}/move/{direction}', [FirewallController::class, 'move'])->name('move');
    });
});

// --- panel administratora ---------------------------------------------------
// Każdy dział ma własne uprawnienie (App\Domain\Access\Permissions::ADMIN) —
// administrator ma wszystkie, support te, które mu nadano.
Route::middleware(['auth', 'not-suspended'])->prefix('panel/admin')->name('panel.admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->middleware('admin:panel')->name('index');

    Route::middleware('admin:admin.hypervisors')->group(function () {
        Route::get('/hypervisors', [AdminController::class, 'hypervisors'])->name('hypervisors');
        Route::get('/hypervisors/{hypervisor}', [AdminController::class, 'showHypervisor'])->name('hypervisors.show');
        Route::post('/hypervisors', [AdminController::class, 'storeHypervisor'])->name('hypervisors.store');
        Route::post('/hypervisors/{hypervisor}/enrollment', [AdminController::class, 'regenerateEnrollment'])->name('hypervisors.enrollment');
        Route::put('/hypervisors/{hypervisor}', [AdminController::class, 'updateHypervisor'])->name('hypervisors.update');
        Route::post('/hypervisors/{hypervisor}/check', [AdminController::class, 'checkHypervisor'])->name('hypervisors.check');
        Route::delete('/hypervisors/{hypervisor}', [AdminController::class, 'destroyHypervisor'])->name('hypervisors.destroy');
    });

    Route::middleware('admin:admin.packages')->group(function () {
        Route::get('/packages', [AdminController::class, 'packages'])->name('packages');
        Route::post('/packages', [AdminController::class, 'storePackage'])->name('packages.store');
        Route::post('/packages/{package}/toggle', [AdminController::class, 'togglePackage'])->name('packages.toggle');
    });

    Route::middleware('admin:admin.templates')->group(function () {
        Route::get('/templates', [AdminController::class, 'templates'])->name('templates');
        Route::post('/templates', [AdminController::class, 'storeTemplate'])->name('templates.store');
        Route::post('/templates/{template}/toggle', [AdminController::class, 'toggleTemplate'])->name('templates.toggle');
        Route::post('/templates/{template}/retry', [AdminController::class, 'retryTemplate'])->name('templates.retry');
        Route::post('/templates/catalog/{key}', [AdminController::class, 'addCatalogTemplate'])->name('templates.catalog');
        Route::put('/templates/{template}', [AdminController::class, 'updateTemplate'])->name('templates.update');
        Route::delete('/templates/{template}', [AdminController::class, 'destroyTemplate'])->name('templates.destroy');
        Route::post('/template-groups', [AdminController::class, 'storeTemplateGroup'])->name('template-groups.store');
        Route::put('/template-groups/{group}', [AdminController::class, 'updateTemplateGroup'])->name('template-groups.update');
        Route::post('/template-groups/{group}/toggle', [AdminController::class, 'toggleTemplateGroup'])->name('template-groups.toggle');
        Route::delete('/template-groups/{group}', [AdminController::class, 'destroyTemplateGroup'])->name('template-groups.destroy');

        Route::get('/downloads/status', [AdminController::class, 'downloadsStatus'])->name('downloads.status');
        Route::get('/isos', [IsoController::class, 'index'])->name('isos');
        Route::post('/isos', [IsoController::class, 'store'])->name('isos.store');
        Route::post('/isos/{iso}/retry', [IsoController::class, 'retry'])->name('isos.retry');
        Route::post('/isos/{iso}/toggle', [IsoController::class, 'toggle'])->name('isos.toggle');
        Route::delete('/isos/{iso}', [IsoController::class, 'destroy'])->name('isos.destroy');
    });

    Route::middleware('admin:admin.ip_pools')->group(function () {
        Route::get('/ip-pools', [AdminController::class, 'ipPools'])->name('ip-pools');
        Route::post('/ip-pools', [AdminController::class, 'storeIpPool'])->name('ip-pools.store');
        Route::delete('/ip-pools/{pool}', [AdminController::class, 'destroyIpPool'])->name('ip-pools.destroy');
    });

    Route::middleware('admin:admin.hypervisors|admin.ip_pools')->group(function () {
        Route::post('/hypervisor-groups', [AdminController::class, 'storeHypervisorGroup'])->name('hypervisor-groups.store');
        Route::put('/hypervisor-groups/{group}', [AdminController::class, 'updateHypervisorGroup'])->name('hypervisor-groups.update');
        Route::delete('/hypervisor-groups/{group}', [AdminController::class, 'destroyHypervisorGroup'])->name('hypervisor-groups.destroy');
    });

    Route::middleware('admin:admin.updates')->group(function () {
        Route::get('/updates', [UpdatesController::class, 'index'])->name('updates');
        Route::get('/updates/status', [UpdatesController::class, 'status'])->name('updates.status');
        Route::post('/updates/panel', [UpdatesController::class, 'updatePanel'])->name('updates.panel');
        Route::post('/updates/nodes', [UpdatesController::class, 'updateAllNodes'])->name('updates.nodes');
        Route::post('/updates/nodes/{hypervisor}', [UpdatesController::class, 'updateNode'])->name('updates.node');
    });

    Route::middleware('admin:admin.servers')->group(function () {
        Route::get('/servers', [AdminController::class, 'servers'])->name('servers');
        Route::post('/servers/bulk', [AdminController::class, 'bulkServers'])->name('servers.bulk');
    });

    Route::middleware('admin:admin.users')->group(function () {
        Route::get('/users', [UsersController::class, 'index'])->name('users');
        Route::get('/users/new', [UsersController::class, 'create'])->name('users.create');
        Route::post('/users', [UsersController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [UsersController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UsersController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/suspend', [UsersController::class, 'suspend'])->name('users.suspend');
        Route::post('/users/{user}/unsuspend', [UsersController::class, 'unsuspend'])->name('users.unsuspend');
        Route::post('/users/{user}/password', [UsersController::class, 'resetPassword'])->name('users.password');
        Route::delete('/users/{user}', [UsersController::class, 'destroy'])->name('users.destroy');
    });
});

// --- rejestracja hypervisora ------------------------------------------------
// Bez uwierzytelnienia: świeży serwer nie ma jeszcze żadnych poświadczeń.
// Ochroną jest jednorazowy, godzinny bilet w adresie.
Route::prefix('enroll/{token}')->name('enroll.')->group(function () {
    Route::get('/', [EnrollmentController::class, 'script'])->name('script');
    Route::get('/whoami', [EnrollmentController::class, 'whoami'])->name('whoami');
    Route::get('/agent.tar.gz', [EnrollmentController::class, 'agentBundle'])->name('bundle');
    Route::post('/complete', [EnrollmentController::class, 'complete'])->name('complete');
});

Route::get('/console/{token}', [ConsoleController::class, 'show'])
    ->middleware('auth')
    ->name('console.show');
