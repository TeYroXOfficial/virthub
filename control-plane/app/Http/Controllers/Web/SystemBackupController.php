<?php

namespace App\Http\Controllers\Web;

use App\Domain\System\SystemBackup;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** System → Kopia panelu: archiwa bazy, .env i plików panelu (wyłącznie administrator). */
class SystemBackupController extends Controller
{
    public function __construct(private readonly SystemBackup $backups) {}

    public function index(): View
    {
        return view('panel.admin.system-backups', [
            'backups' => $this->backups->list(),
            'daily' => SystemBackup::dailyEnabled(),
            'keep' => SystemBackup::keep(),
            'directory' => SystemBackup::directory(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $name = $this->backups->create($request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        return back()->with('status', __('Utworzono kopię :name.', ['name' => $name]));
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate(['keep' => ['required', 'integer', 'min:1', 'max:365']]);
        Setting::put(['system_backup.daily' => $request->boolean('daily') ? '1' : '0', 'system_backup.keep' => (string) $data['keep']]);
        AuditLog::record('system.backup_settings', null, ['daily' => $request->boolean('daily'), 'keep' => (int) $data['keep']]);
        $this->backups->prune();

        return back()->with('status', __('Zapisano ustawienia kopii panelu.'));
    }

    public function download(Request $request, string $name): BinaryFileResponse
    {
        $path = $this->backups->path($name) ?? abort(404);
        AuditLog::record('system.backup_downloaded', null, ['name' => $name], $request->user());

        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }

    public function destroy(Request $request, string $name): RedirectResponse
    {
        abort_unless($this->backups->delete($name, $request->user()), 404);

        return back()->with('status', __('Usunięto kopię :name.', ['name' => $name]));
    }
}
