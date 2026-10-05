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
    Route::get('/forgot-password', [\App\Http\Controllers\Web\PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [\App\Http\Controllers\Web\PasswordResetController::class, 'send'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [\App\Http\Controllers\Web\PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\Web\PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});

// Zmiana języka — także przed zalogowaniem (strona logowania).
Route::post('/locale', function (\Illuminate\Http\Request $request) {
    $locale = $request->validate(['locale' => ['required', \Illuminate\Validation\Rule::in(array_keys(config('virthub.locales')))]])['locale'];
    $request->session()->put('locale', $locale);
    $request->user()?->forceFill(['locale' => $locale])->save();

    return back();
})->name('locale.switch');

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Bilet z systemu rozliczeniowego — celowo poza grupą 'guest', bo klient może
// mieć już aktywną sesję na inne konto (np. wsparcie testujące zgłoszenie).
Route::get('/sso/{token}', SsoController::class)->name('sso.consume');

Route::get('/avatars/{user}', [\App\Http\Controllers\Web\AccountController::class, 'avatar'])
    ->middleware('auth')->whereNumber('user')->name('avatar');

// --- panel ------------------------------------------------------------------
Route::middleware(['auth', 'not-suspended'])->prefix('panel')->name('panel.')->group(function () {
    Route::get('/', [PanelController::class, 'dashboard'])->name('dashboard');

    Route::prefix('/account')->name('account')->controller(\App\Http\Controllers\Web\AccountController::class)->group(function () {
        Route::get('/', 'show');
        Route::put('/profile', 'updateProfile')->name('.profile');
        Route::put('/password', 'updatePassword')->middleware('throttle:10,1')->name('.password');
        Route::post('/logout-others', 'logoutOthers')->middleware('throttle:10,1')->name('.logout-others');
        Route::post('/avatar', 'uploadAvatar')->name('.avatar');
        Route::delete('/avatar', 'deleteAvatar')->name('.avatar.delete');
    });

    Route::get('/servers/new', [PanelController::class, 'createServer'])->name('servers.create');
    Route::post('/servers', [PanelController::class, 'storeServer'])->name('servers.store');
    Route::get('/servers/{server}', [PanelController::class, 'showServer'])->name('servers.show');
    Route::post('/servers/{server}/console', [ConsoleController::class, 'open'])->name('servers.console');
    Route::post('/servers/{server}/rebuild', [ServerActionsController::class, 'rebuild'])->name('servers.rebuild');
    Route::post('/servers/{server}/resize', [ServerActionsController::class, 'resize'])->middleware('throttle:10,1')->name('servers.resize');
    Route::post('/servers/{server}/iso', [ServerActionsController::class, 'iso'])->name('servers.iso');
    Route::post('/servers/{server}/password', [ServerActionsController::class, 'resetPassword'])->name('servers.password');
    Route::get('/servers/{server}/status', [ServerActionsController::class, 'status'])->name('servers.status');
    Route::delete('/servers/{server}', [ServerActionsController::class, 'destroy'])->name('servers.destroy');
    Route::post('/servers/{server}/purge', [ServerActionsController::class, 'purge'])->name('servers.purge');
    Route::post('/servers/{server}/detect-os', [ServerActionsController::class, 'detectOs'])->name('servers.detect-os');
    Route::post('/servers/{server}/traffic', [ServerActionsController::class, 'traffic'])->name('servers.traffic');
    Route::post('/servers/{server}/cpu-limit', [ServerActionsController::class, 'cpuLimit'])->name('servers.cpu-limit');
    Route::put('/servers/{server}/ips/{address}/rdns', [ServerActionsController::class, 'rdns'])->middleware('throttle:10,1')->name('servers.rdns');
    Route::post('/servers/{server}/ports', [\App\Http\Controllers\Web\PortForwardController::class, 'store'])->name('servers.ports.store');
    Route::delete('/servers/{server}/ports/{forward}', [\App\Http\Controllers\Web\PortForwardController::class, 'destroy'])->name('servers.ports.destroy');

    // --- aplikacje (serwery gier, boty) ---
    Route::prefix('/apps')->name('apps.')->controller(\App\Http\Controllers\Web\AppController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{app}', 'show')->name('show');
        Route::get('/{app}/console', 'terminal')->name('terminal');
        Route::get('/{app}/startup', 'startup')->name('startup');
        Route::put('/{app}/startup', 'updateStartup')->name('startup.update');
        Route::get('/{app}/settings', 'settings')->name('settings');
        Route::put('/{app}/name', 'rename')->name('rename');
        Route::put('/{app}/sftp-password', 'sftpPassword')->middleware('throttle:10,1')->name('sftp-password');
        Route::post('/{app}/reinstall', 'reinstall')->name('reinstall');
        Route::delete('/{app}', 'destroy')->name('destroy');
        Route::get('/{app}/status', 'status')->name('status');
        Route::get('/{app}/logs', 'logs')->name('logs');
        Route::post('/{app}/power', 'power')->name('power');
        Route::post('/{app}/command', 'command')->name('command');
        Route::post('/{app}/console', 'consoleSession')->name('console');
    });
    Route::prefix('/apps/{app}')->name('apps.')->controller(\App\Http\Controllers\Web\AppContentController::class)->group(function () {
        Route::get('/modpacks', 'modpacks')->name('modpacks');
        Route::get('/modpacks/{source}/{project}', 'modpack')->where(['source' => '[a-z]+', 'project' => '[A-Za-z0-9._-]+'])->name('modpacks.show');
        Route::post('/modpacks', 'installModpack')->middleware('throttle:10,1')->name('modpacks.install');
        Route::post('/loader', 'installLoader')->middleware('throttle:10,1')->name('loader.install');
        Route::get('/addons', 'addons')->name('addons');
        Route::get('/addons/{source}/{project}', 'addon')->where(['source' => '[a-z]+', 'project' => '[A-Za-z0-9._-]+'])->name('addons.show');
        Route::post('/addons', 'installAddon')->middleware('throttle:30,1')->name('addons.install');
        Route::delete('/addons/{addon}', 'removeAddon')->name('addons.destroy');
    });
    Route::prefix('/apps/{app}/files')->name('apps.files')->controller(\App\Http\Controllers\Web\AppFilesController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/edit', 'edit')->name('.edit');
        Route::put('/edit', 'save')->name('.save');
        Route::get('/download', 'download')->name('.download');
        Route::post('/upload', 'upload')->name('.upload');
        Route::post('/mkdir', 'mkdir')->name('.mkdir');
        Route::post('/delete', 'delete')->name('.delete');
        Route::post('/rename', 'rename')->name('.rename');
        Route::post('/decompress', 'decompress')->name('.decompress');
    });

    // Sklep i rozliczenia (wbudowany billing).
    Route::prefix('/store')->name('store')->controller(\App\Http\Controllers\Web\StoreController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{product}', 'show')->name('.product');
        Route::post('/{product}', 'order')->middleware('throttle:10,1')->name('.order');
    });
    Route::prefix('/billing')->name('billing')->controller(\App\Http\Controllers\Web\BillingController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/wallet', 'wallet')->name('.wallet');
        Route::post('/wallet', 'topup')->middleware('throttle:10,1')->name('.topup');
        Route::get('/invoices/{invoice}', 'invoice')->name('.invoice');
        Route::post('/invoices/{invoice}/wallet', 'payWithWallet')->middleware('throttle:10,1')->name('.invoice.wallet');
        Route::get('/services/{service}', 'service')->name('.service');
        Route::post('/services/{service}/cancel', 'cancel')->name('.service.cancel');
        Route::post('/services/{service}/keepalive', 'keepalive')->middleware('throttle:20,1')->name('.service.keepalive');
    });
    Route::post('/billing/invoices/{invoice}/pay/{gateway}', [\App\Http\Controllers\Web\PaymentController::class, 'pay'])
        ->whereIn('gateway', ['stripe', 'paypal'])->middleware('throttle:10,1')->name('billing.pay');
    Route::get('/billing/invoices/{invoice}/return/{gateway}', [\App\Http\Controllers\Web\PaymentController::class, 'return'])
        ->whereIn('gateway', ['stripe', 'paypal'])->middleware('throttle:20,1')->name('billing.return');

    Route::prefix('/tickets')->name('tickets.')->controller(\App\Http\Controllers\Web\TicketController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
        Route::get('/attachments/{attachment}', 'attachment')->name('attachment');
        Route::get('/{ticket}', 'show')->name('show');
        Route::post('/{ticket}/reply', 'reply')->middleware('throttle:20,1')->name('reply');
        Route::post('/{ticket}/close', 'close')->name('close');
        Route::post('/{ticket}/reopen', 'reopen')->name('reopen');
    });

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
    Route::get('/logs', [AdminController::class, 'logs'])->middleware('admin')->name('logs');

    Route::middleware('admin:admin.hypervisors')->group(function () {
        Route::get('/hypervisors', [AdminController::class, 'hypervisors'])->name('hypervisors');
        Route::get('/hypervisors/{hypervisor}', [AdminController::class, 'showHypervisor'])->name('hypervisors.show');
        Route::get('/monitoring', [\App\Http\Controllers\Web\MonitoringController::class, 'index'])->name('monitoring');
        Route::get('/security', [\App\Http\Controllers\Web\NodeSecurityController::class, 'index'])->name('security');
        Route::post('/security/{hypervisor}/check', [\App\Http\Controllers\Web\NodeSecurityController::class, 'check'])
            ->middleware('throttle:20,1')->name('security.check');
        Route::put('/security/{hypervisor}/nested', [\App\Http\Controllers\Web\NodeSecurityController::class, 'nested'])
            ->middleware('throttle:10,1')->name('security.nested');
        Route::get('/monitoring/{hypervisor}/data', [\App\Http\Controllers\Web\MonitoringController::class, 'data'])
            ->middleware('throttle:60,1')->name('monitoring.data');
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
        Route::put('/packages/{package}', [AdminController::class, 'updatePackage'])->name('packages.update');
    });

    Route::middleware('admin:admin.templates')->group(function () {
        Route::get('/templates', [AdminController::class, 'templates'])->name('templates');
        Route::post('/templates', [AdminController::class, 'storeTemplate'])->name('templates.store');
        Route::post('/templates/{template}/toggle', [AdminController::class, 'toggleTemplate'])->name('templates.toggle');
        Route::post('/templates/{template}/retry', [AdminController::class, 'retryTemplate'])->name('templates.retry');
        Route::post('/templates/catalog/{key}', [AdminController::class, 'addCatalogTemplate'])->name('templates.catalog');
        Route::post('/templates/kvm-catalog/{key}', [AdminController::class, 'addKvmCatalogTemplate'])->name('templates.kvm-catalog');
        Route::post('/templates/build/{key}', [AdminController::class, 'buildTemplate'])->name('templates.build');
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
        // Sieć: bloki IP i przegląd adresów.
        Route::controller(\App\Http\Controllers\Web\NetworkController::class)->group(function () {
            Route::get('/ip-pools', 'blocks')->name('ip-pools');
            Route::post('/ip-pools', 'store')->name('ip-pools.store');
            Route::get('/ip-pools/{pool}', 'show')->name('ip-pools.show');
            Route::put('/ip-pools/{pool}', 'update')->name('ip-pools.update');
            Route::delete('/ip-pools/{pool}', 'destroy')->name('ip-pools.destroy');
            Route::post('/ip-pools/{pool}/addresses', 'addAddresses')->middleware('throttle:30,1')->name('ip-pools.addresses');
            Route::post('/ip-addresses/{address}', 'address')->name('ip-addresses.update');
            Route::get('/network/ipv4', 'ipv4')->name('network.ipv4');
            Route::get('/network/nat', 'nat')->name('network.nat');
            Route::get('/network/ipv6', 'ipv6')->name('network.ipv6');
        });
    });

    Route::middleware('admin:admin.hypervisors|admin.ip_pools')->group(function () {
        Route::get('/hypervisor-groups', [AdminController::class, 'hypervisorGroups'])->name('hypervisor-groups');
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

    Route::middleware('admin:admin.apps')->prefix('/apps')->name('apps')->controller(\App\Http\Controllers\Web\AppAdminController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/eggs', 'eggs')->name('.eggs');
        Route::post('/eggs', 'importEgg')->name('.eggs.import');
        Route::post('/eggs/builtin', 'builtinEggs')->name('.eggs.builtin');
        Route::post('/eggs/{egg}/toggle', 'toggleEgg')->name('.eggs.toggle');
        Route::delete('/eggs/{egg}', 'deleteEgg')->name('.eggs.destroy');
        Route::get('/plans', 'plans')->name('.plans');
        Route::post('/plans', 'storePlan')->name('.plans.store');
        Route::post('/plans/{plan}/toggle', 'togglePlan')->name('.plans.toggle');
        Route::put('/plans/{plan}', 'updatePlan')->name('.plans.update');
        Route::delete('/plans/{plan}', 'deletePlan')->name('.plans.destroy');
        Route::get('/pterodactyl', [\App\Http\Controllers\Web\PterodactylMigrationController::class, 'index'])->name('.pterodactyl');
        Route::post('/pterodactyl/connect', [\App\Http\Controllers\Web\PterodactylMigrationController::class, 'connect'])->name('.pterodactyl.connect');
        Route::post('/pterodactyl/disconnect', [\App\Http\Controllers\Web\PterodactylMigrationController::class, 'disconnect'])->name('.pterodactyl.disconnect');
        Route::post('/pterodactyl/migrate', [\App\Http\Controllers\Web\PterodactylMigrationController::class, 'migrate'])->name('.pterodactyl.migrate');
        Route::post('/{app}/suspend', 'suspend')->name('.suspend');
        Route::put('/{app}/resources', 'resources')->name('.resources');
        Route::post('/{app}/abuse-exempt', 'abuseExempt')->name('.abuse-exempt');
        Route::post('/{app}/purge', 'purge')->name('.purge');
    });

    Route::middleware('admin:admin.settings')->prefix('/network/dns')->name('network.dns')->controller(\App\Http\Controllers\Web\ReverseDnsSettingsController::class)->group(function () {
        Route::get('/', 'show');
        Route::put('/', 'update')->name('.update');
        Route::post('/test', 'test')->middleware('throttle:10,1')->name('.test');
    });

    Route::middleware('admin:admin.settings')->prefix('/emails')->name('emails')->controller(\App\Http\Controllers\Web\EmailTemplateController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{key}/{locale}', 'edit')->name('.edit');
        Route::put('/{key}/{locale}', 'update')->name('.update');
        Route::delete('/{key}/{locale}', 'reset')->name('.reset');
        Route::post('/{key}/{locale}/test', 'test')->middleware('throttle:10,1')->name('.test');
    });

    Route::middleware('admin:admin.settings')->prefix('/mail')->name('mail')->controller(\App\Http\Controllers\Web\MailSettingsController::class)->group(function () {
        Route::get('/', 'show');
        Route::put('/', 'update')->name('.update');
        Route::post('/test', 'test')->middleware('throttle:5,1')->name('.test');
    });

    Route::middleware('admin:admin.billing')->prefix('/billing')->name('billing')->controller(\App\Http\Controllers\Web\BillingAdminController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/settings', 'settings')->name('.settings');
        Route::put('/settings', 'saveSettings')->name('.settings.update');
        // Klucze bramek — tylko administrator.
        Route::get('/gateways', [\App\Http\Controllers\Web\GatewaySettingsController::class, 'show'])->middleware('admin')->name('.gateways');
        Route::put('/gateways/{gateway}', [\App\Http\Controllers\Web\GatewaySettingsController::class, 'update'])->whereIn('gateway', ['stripe', 'paypal'])->middleware('admin')->name('.gateways.update');
        Route::post('/gateways/{gateway}/test', [\App\Http\Controllers\Web\GatewaySettingsController::class, 'test'])->whereIn('gateway', ['stripe', 'paypal'])->middleware(['admin', 'throttle:10,1'])->name('.gateways.test');
        Route::get('/catalog', 'catalog')->name('.catalog');
        Route::post('/categories', 'storeCategory')->name('.categories.store');
        Route::put('/categories/{category}', 'updateCategory')->name('.categories.update');
        Route::delete('/categories/{category}', 'destroyCategory')->name('.categories.destroy');
        Route::get('/products/new', 'createProduct')->name('.products.create');
        Route::post('/products', 'storeProduct')->name('.products.store');
        Route::get('/products/{product}', 'editProduct')->name('.products.edit');
        Route::put('/products/{product}', 'updateProduct')->name('.products.update');
        Route::delete('/products/{product}', 'destroyProduct')->name('.products.destroy');
        Route::get('/services', 'services')->name('.services');
        Route::get('/services/{service}', 'service')->name('.service');
        Route::put('/services/{service}', 'updateService')->name('.service.update');
        Route::post('/services/{service}/action', 'serviceAction')->name('.service.action');
        Route::get('/invoices', 'invoices')->name('.invoices');
        Route::get('/invoices/{invoice}', 'invoice')->name('.invoice');
        Route::post('/invoices/{invoice}/action', 'invoiceAction')->name('.invoice.action');
        Route::get('/customers', 'customers')->name('.customers');
        Route::get('/customers/{user}', 'customer')->name('.customer');
        Route::post('/customers/{user}/wallet', 'adjustWallet')->name('.customer.wallet');
    });

    Route::middleware('admin:admin.tickets')->prefix('/tickets')->name('tickets')->controller(\App\Http\Controllers\Web\TicketAdminController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/settings', 'settings')->name('.settings');
        Route::put('/settings', 'saveSettings')->name('.settings.update');
        Route::post('/departments', 'storeDepartment')->name('.departments.store');
        Route::put('/departments/{department}', 'updateDepartment')->name('.departments.update');
        Route::delete('/departments/{department}', 'destroyDepartment')->name('.departments.destroy');
        Route::post('/canned', 'storeCanned')->name('.canned.store');
        Route::put('/canned/{canned}', 'updateCanned')->name('.canned.update');
        Route::delete('/canned/{canned}', 'destroyCanned')->name('.canned.destroy');
        Route::get('/{ticket}', 'show')->name('.show');
        Route::post('/{ticket}/reply', 'reply')->name('.reply');
        Route::put('/{ticket}', 'update')->name('.update');
        Route::delete('/{ticket}', 'destroy')->name('.destroy');
    });

    Route::get('/services', [AdminController::class, 'services'])->middleware('admin:admin.servers|admin.apps')->name('services');

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

// --- webhooki bramek płatności -----------------------------------------------
// Bez sesji i CSRF: wiarygodność sprawdza bramka (podpis Stripe, weryfikacja PayPal).
Route::post('/billing/webhooks/{gateway}', [\App\Http\Controllers\Web\PaymentController::class, 'webhook'])
    ->whereIn('gateway', ['stripe', 'paypal'])->middleware('throttle:120,1')->name('billing.webhook');

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
