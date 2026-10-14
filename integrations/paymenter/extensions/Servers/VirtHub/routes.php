<?php

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\VirtHub\VirtHub;

// Akcje z zakładki „Sterowanie serwerem” — tylko dla właściciela usługi.
Route::middleware(['web', 'auth'])->prefix('extensions/servers/virthub/{service}')->name('extensions.servers.virthub.')->group(function () {
    $handle = function (Request $request, Service $service, callable $action, string $success) {
        abort_unless((int) $service->user_id === (int) $request->user()->id, 404);
        try {
            $result = $action($service, $request);
            session()->flash('virthub_message', is_string($result) ? $result : VirtHub::t($success));
        } catch (\Throwable $e) {
            session()->flash('virthub_error', $e->getMessage());
        }

        return redirect()->route('services.show', ['service' => $service, 'tab' => 'virthub']);
    };

    Route::post('/reinstall', fn (Request $r, Service $service) => $handle($r, $service,
        fn ($s, $r) => VirtHub::callForService($s, 'reinstallFromRequest', [$r->only(['template', 'confirm', 'ssh_keys'])]), 'reinstall_started'))->name('reinstall');
    Route::post('/password', fn (Request $r, Service $service) => $handle($r, $service,
        fn ($s) => VirtHub::callForService($s, 'resetPasswordFromRequest'), 'reset_password_done'))->name('password');
    Route::post('/credentials', fn (Request $r, Service $service) => $handle($r, $service,
        fn ($s) => VirtHub::callForService($s, 'fetchPasswordFromRequest') ? VirtHub::t('password_saved') : VirtHub::t('password_none'), 'password_saved'))->name('credentials');
});
