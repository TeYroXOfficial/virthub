<?php

use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\Web\AccountController;
use App\Http\Controllers\Web\AdminController;
use App\Http\Controllers\Web\AppAdminController;
use App\Http\Controllers\Web\AppContentController;
use App\Http\Controllers\Web\AppController;
use App\Http\Controllers\Web\AppDatabaseController;
use App\Http\Controllers\Web\AppFilesController;
use App\Http\Controllers\Web\AppNetworkController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\BillingAdminController;
use App\Http\Controllers\Web\BillingController;
use App\Http\Controllers\Web\CloudServerController;
use App\Http\Controllers\Web\ConsoleController;
use App\Http\Controllers\Web\DatabaseHostController;
use App\Http\Controllers\Web\EmailTemplateController;
use App\Http\Controllers\Web\FirewallController;
use App\Http\Controllers\Web\GatewaySettingsController;
use App\Http\Controllers\Web\ImpersonationController;
use App\Http\Controllers\Web\IsoController;
use App\Http\Controllers\Web\LanguageController;
use App\Http\Controllers\Web\LicenseController;
use App\Http\Controllers\Web\MailSettingsController;
use App\Http\Controllers\Web\MonitoringController;
use App\Http\Controllers\Web\NetworkController;
use App\Http\Controllers\Web\NodeSecurityController;
use App\Http\Controllers\Web\PanelController;
use App\Http\Controllers\Web\PasswordResetController;
use App\Http\Controllers\Web\PaymentController;
use App\Http\Controllers\Web\PortForwardController;
use App\Http\Controllers\Web\ProviderAccountController;
use App\Http\Controllers\Web\PterodactylMigrationController;
use App\Http\Controllers\Web\ReverseDnsSettingsController;
use App\Http\Controllers\Web\ServerActionsController;
use App\Http\Controllers\Web\SsoController;
use App\Http\Controllers\Web\StoreController;
use App\Http\Controllers\Web\TicketAdminController;
use App\Http\Controllers\Web\TicketController;
use App\Http\Controllers\Web\UpdatesController;
use App\Http\Controllers\Web\UsersController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::get('/', fn () => redirect()->route('panel.dashboard'));

// --- logowanie --------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});

// Zmiana języka — także przed zalogowaniem (strona logowania).
Route::post('/locale', function (Request $request) {
    $locale = $request->validate(['locale' => ['required', Rule::in(array_keys(config('virthub.locales')))]])['locale'];
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

// Powrót z „Zaloguj jako” na konto administratora.
Route::post('/impersonate/stop', [ImpersonationController::class, 'stop'])
    ->middleware('auth')->name('impersonate.stop');

Route::get('/avatars/{user}', [AccountController::class, 'avatar'])
    ->middleware('auth')->whereNumber('user')->name('avatar');

// --- panel ------------------------------------------------------------------
Route::middleware(['auth', 'not-suspended'])->prefix('panel')->name('panel.')->group(function () {
    Route::get('/', [PanelController::class, 'dashboard'])->name('dashboard');

    Route::prefix('/account')->name('account')->controller(AccountController::class)->group(function () {
        Route::get('/', 'show');
        Route::put('/profile', 'updateProfile')->middleware('not-impersonating')->name('.profile');
        Route::put('/password', 'updatePassword')->middleware(['not-impersonating', 'throttle:10,1'])->name('.password');
        Route::post('/logout-others', 'logoutOthers')->middleware(['not-impersonating', 'throttle:10,1'])->name('.logout-others');
        Route::post('/avatar', 'uploadAvatar')->name('.avatar');
        Route::delete('/avatar', 'deleteAvatar')->name('.avatar.delete');
    });

    Route::get('/servers/new', [PanelController::class, 'createServer'])->name('servers.create');
    Route::post('/servers', [PanelController::class, 'storeServer'])->name('servers.store');
    Route::get('/servers/{server}', [PanelController::class, 'showServer'])->name('servers.show');
    // VPS u dostawców zewnętrznych (reselling przez addony).
    Route::prefix('/cloud/{server}')->name('cloud.')->controller(CloudServerController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::get('/status', 'status')->name('status');
        Route::post('/power', 'power')->middleware('throttle:20,1')->name('power');
        Route::post('/reinstall', 'reinstall')->middleware('throttle:5,1')->name('reinstall');
        Route::post('/console', 'console')->middleware('throttle:20,1')->name('console');
        Route::post('/rdns', 'rdns')->middleware('throttle:10,1')->name('rdns');
        Route::put('/name', 'rename')->name('rename');
    });
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
    Route::post('/servers/{server}/ports', [PortForwardController::class, 'store'])->name('servers.ports.store');
    Route::delete('/servers/{server}/ports/{forward}', [PortForwardController::class, 'destroy'])->name('servers.ports.destroy');

    // --- aplikacje (serwery gier, boty) ---
    Route::prefix('/apps')->name('apps.')->controller(AppController::class)->group(function () {
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
    Route::prefix('/apps/{app}')->name('apps.')->controller(AppContentController::class)->group(function () {
        Route::get('/modpacks', 'modpacks')->name('modpacks');
        Route::get('/modpacks/{source}/{project}', 'modpack')->where(['source' => '[a-z]+', 'project' => '[A-Za-z0-9._-]+'])->name('modpacks.show');
        Route::post('/modpacks', 'installModpack')->middleware('throttle:10,1')->name('modpacks.install');
        Route::post('/loader', 'installLoader')->middleware('throttle:10,1')->name('loader.install');
        Route::get('/addons', 'addons')->name('addons');
        Route::get('/addons/{source}/{project}', 'addon')->where(['source' => '[a-z]+', 'project' => '[A-Za-z0-9._-]+'])->name('addons.show');
        Route::post('/addons', 'installAddon')->middleware('throttle:30,1')->name('addons.install');
        Route::delete('/addons/{addon}', 'removeAddon')->name('addons.destroy');
    });
    Route::prefix('/apps/{app}')->name('apps.')->controller(AppNetworkController::class)->group(function () {
        Route::get('/network', 'index')->name('network');
        Route::post('/ports', 'store')->middleware('throttle:20,1')->name('ports.store');
        Route::post('/ports/{allocation}/primary', 'primary')->name('ports.primary');
        Route::put('/ports/{allocation}/note', 'note')->name('ports.note');
        Route::delete('/ports/{allocation}', 'destroy')->name('ports.destroy');
        Route::put('/ports-limit', 'limit')->name('ports.limit');
    });
    Route::prefix('/apps/{app}/databases')->name('apps.databases')->controller(AppDatabaseController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('.store');
        Route::put('/limit', 'limit')->name('.limit');
        Route::post('/{database}/password', 'password')->middleware('throttle:10,1')->name('.password');
        Route::delete('/{database}', 'destroy')->name('.destroy');
        Route::get('/{database}/browse', 'browse')->name('.browse');
        Route::post('/{database}/query', 'query')->middleware('throttle:60,1')->name('.query');
        Route::get('/{database}/tables/{table}', 'table')->where('table', '[A-Za-z0-9_$\-]{1,64}')->name('.table');
        Route::get('/{database}/export', 'export')->middleware('throttle:10,1')->name('.export');
        Route::post('/{database}/import', 'import')->middleware('throttle:10,1')->name('.import');
    });
    Route::prefix('/apps/{app}/files')->name('apps.files')->controller(AppFilesController::class)->group(function () {
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
    Route::prefix('/store')->name('store')->controller(StoreController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{product}', 'show')->name('.product');
        Route::post('/{product}', 'order')->middleware('throttle:10,1')->name('.order');
    });
    Route::prefix('/billing')->name('billing')->controller(BillingController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/wallet', 'wallet')->name('.wallet');
        Route::post('/wallet', 'topup')->middleware('throttle:10,1')->name('.topup');
        Route::get('/invoices/{invoice}', 'invoice')->name('.invoice');
        Route::post('/invoices/{invoice}/wallet', 'payWithWallet')->middleware('throttle:10,1')->name('.invoice.wallet');
        Route::get('/services/{service}', 'service')->name('.service');
        Route::post('/services/{service}/cancel', 'cancel')->name('.service.cancel');
        Route::post('/services/{service}/keepalive', 'keepalive')->middleware('throttle:20,1')->name('.service.keepalive');
    });
    Route::post('/billing/invoices/{invoice}/pay/{gateway}', [PaymentController::class, 'pay'])
        ->whereIn('gateway', ['stripe', 'paypal'])->middleware('throttle:10,1')->name('billing.pay');
    Route::get('/billing/invoices/{invoice}/return/{gateway}', [PaymentController::class, 'return'])
        ->whereIn('gateway', ['stripe', 'paypal'])->middleware('throttle:20,1')->name('billing.return');

    Route::prefix('/tickets')->name('tickets.')->controller(TicketController::class)->group(function () {
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
        Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring');
        Route::get('/security', [NodeSecurityController::class, 'index'])->name('security');
        Route::post('/security/{hypervisor}/check', [NodeSecurityController::class, 'check'])
            ->middleware('throttle:20,1')->name('security.check');
        Route::put('/security/{hypervisor}/nested', [NodeSecurityController::class, 'nested'])
            ->middleware('throttle:10,1')->name('security.nested');
        Route::get('/monitoring/{hypervisor}/data', [MonitoringController::class, 'data'])
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
        Route::controller(NetworkController::class)->group(function () {
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

    // Licencja, addony i konta dostawców zewnętrznych — tylko administrator.
    Route::middleware('admin')->group(function () {
        Route::get('/license', [LicenseController::class, 'index'])->name('license');
        Route::post('/license', [LicenseController::class, 'activate'])->middleware('throttle:10,1')->name('license.activate');
        Route::post('/license/refresh', [LicenseController::class, 'refresh'])->middleware('throttle:10,1')->name('license.refresh');
        Route::delete('/license', [LicenseController::class, 'remove'])->name('license.remove');
        Route::post('/addons/{addon}/install', [LicenseController::class, 'install'])->middleware('throttle:10,1')->where('addon', '[a-z][a-z0-9-]{1,39}')->name('addons.install');
        Route::post('/addons/{addon}/toggle', [LicenseController::class, 'toggle'])->where('addon', '[a-z][a-z0-9-]{1,39}')->name('addons.toggle');
        Route::delete('/addons/{addon}', [LicenseController::class, 'uninstall'])->where('addon', '[a-z][a-z0-9-]{1,39}')->name('addons.uninstall');

        Route::prefix('/languages')->name('languages')->controller(LanguageController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store')->name('.store');
            Route::post('/default', 'setDefault')->name('.default');
            Route::put('/{language}', 'update')->name('.update');
            Route::delete('/{language}', 'destroy')->name('.destroy');
            Route::get('/{language}/translations', 'translations')->name('.translations');
            Route::put('/{language}/translations', 'saveTranslations')->name('.translations.save');
            Route::get('/{language}/export', 'export')->name('.export');
            Route::post('/{language}/import', 'import')->middleware('throttle:10,1')->name('.import');
        });
        Route::get('/providers', [ProviderAccountController::class, 'index'])->name('providers');
        Route::post('/providers', [ProviderAccountController::class, 'store'])->middleware('throttle:10,1')->name('providers.store');
        Route::put('/providers/{account}', [ProviderAccountController::class, 'update'])->name('providers.update');
        Route::post('/providers/{account}/test', [ProviderAccountController::class, 'test'])->middleware('throttle:20,1')->name('providers.test');
        Route::delete('/providers/{account}', [ProviderAccountController::class, 'destroy'])->name('providers.destroy');
    });
    // Katalog dostawcy dla formularza produktu (personel billingu).
    Route::get('/providers/{account}/catalog', [ProviderAccountController::class, 'catalog'])
        ->middleware(['admin:admin.billing', 'throttle:30,1'])->name('providers.catalog');

    Route::middleware('admin:admin.updates')->group(function () {
        Route::get('/updates', [UpdatesController::class, 'index'])->name('updates');
        Route::get('/updates/status', [UpdatesController::class, 'status'])->name('updates.status');
        Route::post('/updates/panel', [UpdatesController::class, 'updatePanel'])->name('updates.panel');
        Route::post('/updates/nodes', [UpdatesController::class, 'updateAllNodes'])->name('updates.nodes');
        Route::post('/updates/nodes/{hypervisor}', [UpdatesController::class, 'updateNode'])->name('updates.node');
    });

    Route::middleware('admin:admin.apps')->prefix('/apps')->name('apps')->controller(AppAdminController::class)->group(function () {
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
        Route::get('/databases', [DatabaseHostController::class, 'index'])->name('.databases');
        // Serwery baz (konta administracyjne MySQL) — tylko administrator.
        Route::post('/database-hosts/install', [DatabaseHostController::class, 'install'])->middleware(['admin', 'throttle:5,1'])->name('.database-hosts.install');
        Route::delete('/database-hosts/install/{node}', [DatabaseHostController::class, 'dismissInstall'])->middleware('admin')->name('.database-hosts.install.dismiss');
        Route::post('/database-hosts', [DatabaseHostController::class, 'store'])->middleware('admin')->name('.database-hosts.store');
        Route::put('/database-hosts/{host}', [DatabaseHostController::class, 'update'])->middleware('admin')->name('.database-hosts.update');
        Route::post('/database-hosts/{host}/test', [DatabaseHostController::class, 'test'])->middleware(['admin', 'throttle:20,1'])->name('.database-hosts.test');
        Route::delete('/database-hosts/{host}', [DatabaseHostController::class, 'destroy'])->middleware('admin')->name('.database-hosts.destroy');
        Route::get('/pterodactyl', [PterodactylMigrationController::class, 'index'])->name('.pterodactyl');
        Route::post('/pterodactyl/connect', [PterodactylMigrationController::class, 'connect'])->name('.pterodactyl.connect');
        Route::post('/pterodactyl/disconnect', [PterodactylMigrationController::class, 'disconnect'])->name('.pterodactyl.disconnect');
        Route::post('/pterodactyl/migrate', [PterodactylMigrationController::class, 'migrate'])->name('.pterodactyl.migrate');
        Route::post('/{app}/suspend', 'suspend')->name('.suspend');
        Route::put('/{app}/resources', 'resources')->name('.resources');
        Route::post('/{app}/abuse-exempt', 'abuseExempt')->name('.abuse-exempt');
        Route::post('/{app}/purge', 'purge')->name('.purge');
    });

    Route::middleware('admin:admin.settings')->prefix('/network/dns')->name('network.dns')->controller(ReverseDnsSettingsController::class)->group(function () {
        Route::get('/', 'show');
        Route::put('/', 'update')->name('.update');
        Route::post('/test', 'test')->middleware('throttle:10,1')->name('.test');
    });

    Route::middleware('admin:admin.settings')->prefix('/emails')->name('emails')->controller(EmailTemplateController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/{key}/{locale}', 'edit')->name('.edit');
        Route::put('/{key}/{locale}', 'update')->name('.update');
        Route::delete('/{key}/{locale}', 'reset')->name('.reset');
        Route::post('/{key}/{locale}/test', 'test')->middleware('throttle:10,1')->name('.test');
    });

    Route::middleware('admin:admin.settings')->prefix('/mail')->name('mail')->controller(MailSettingsController::class)->group(function () {
        Route::get('/', 'show');
        Route::put('/', 'update')->name('.update');
        Route::post('/test', 'test')->middleware('throttle:5,1')->name('.test');
    });

    Route::middleware('admin:admin.billing')->prefix('/billing')->name('billing')->controller(BillingAdminController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/settings', 'settings')->name('.settings');
        Route::put('/settings', 'saveSettings')->name('.settings.update');
        // Klucze bramek — tylko administrator.
        Route::get('/gateways', [GatewaySettingsController::class, 'show'])->middleware('admin')->name('.gateways');
        Route::put('/gateways/{gateway}', [GatewaySettingsController::class, 'update'])->whereIn('gateway', ['stripe', 'paypal'])->middleware('admin')->name('.gateways.update');
        Route::post('/gateways/{gateway}/test', [GatewaySettingsController::class, 'test'])->whereIn('gateway', ['stripe', 'paypal'])->middleware(['admin', 'throttle:10,1'])->name('.gateways.test');
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

    Route::middleware('admin:admin.tickets')->prefix('/tickets')->name('tickets')->controller(TicketAdminController::class)->group(function () {
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
        Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])
            ->middleware(['admin', 'throttle:20,1'])->name('users.impersonate');
    });
});

// --- webhooki bramek płatności -----------------------------------------------
// Bez sesji i CSRF: wiarygodność sprawdza bramka (podpis Stripe, weryfikacja PayPal).
Route::post('/billing/webhooks/{gateway}', [PaymentController::class, 'webhook'])
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
