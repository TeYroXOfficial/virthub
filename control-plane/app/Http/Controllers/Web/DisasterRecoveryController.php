<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\DisasterRecovery;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BackupPlan;
use App\Models\BackupTarget;
use App\Models\Hypervisor;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Hypervisor → Disaster recovery: polityka kopii, zrzuty konfiguracji, odtworzenie na zastępczym węźle. */
class DisasterRecoveryController extends Controller
{
    public function __construct(private readonly DisasterRecovery $dr) {}

    public function show(Hypervisor $hypervisor): View
    {
        return view('panel.admin.dr', [
            'node' => $hypervisor->load(['drBackupPlan', 'drTarget']),
            'inventory' => $this->dr->inventory($hypervisor),
            'plans' => BackupPlan::query()->orderBy('name')->get(),
            'targets' => BackupTarget::query()->where('is_active', true)->orderBy('name')->get(),
            'replacements' => Hypervisor::query()->available()->where('virtualization', 'kvm')->whereKeyNot($hypervisor->id)->orderBy('name')->get(),
            'preScript' => (string) Setting::get('dr.pre_script', ''),
            'postScript' => (string) Setting::get('dr.post_script', ''),
            'hookLogs' => AuditLog::query()->where('action', 'hypervisor.dr_hook')->latest('id')->limit(5)->get(),
        ]);
    }

    /** Skrypty przed i po odtworzeniu (wspólne dla wszystkich węzłów) — wyłącznie administrator. */
    public function scripts(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'pre_script' => ['nullable', 'string', 'max:65536'],
            'post_script' => ['nullable', 'string', 'max:65536'],
        ]);
        Setting::put(['dr.pre_script' => ($data['pre_script'] ?? '') ?: null, 'dr.post_script' => ($data['post_script'] ?? '') ?: null]);
        AuditLog::record('dr.scripts', null, ['pre' => ! empty($data['pre_script']), 'post' => ! empty($data['post_script'])]);

        return back()->with('status', __('Zapisano skrypty DR.'));
    }

    public function update(Request $request, Hypervisor $hypervisor): RedirectResponse
    {
        $data = $request->validate([
            'dr_backup_plan_id' => ['nullable', 'integer', Rule::exists('backup_plans', 'id')],
            'dr_target_id' => ['nullable', 'integer', Rule::exists('backup_targets', 'id')],
            'dr_snapshot_hours' => ['required', 'integer', 'min:1', 'max:720'],
        ]);
        $hypervisor->update($data);
        AuditLog::record('hypervisor.dr_policy', $hypervisor, $data);

        return back()->with('status', __('Zapisano politykę DR węzła :name.', ['name' => $hypervisor->name]));
    }

    public function snapshot(Hypervisor $hypervisor): RedirectResponse
    {
        $this->dr->snapshot($hypervisor);

        return back()->with('status', __('Zlecono zrzut konfiguracji węzła — wynik pojawi się za chwilę.'));
    }

    public function recover(Request $request, Hypervisor $hypervisor): RedirectResponse
    {
        $data = $request->validate([
            'replacement_id' => ['required', 'integer', Rule::exists('hypervisors', 'id')],
            'confirm' => ['accepted'],
        ]);
        $report = $this->dr->recover($hypervisor, Hypervisor::findOrFail($data['replacement_id']), $request->user());

        return back()->with('status', __('Odtwarzanie: :queued maszyn w kolejce, :skipped pominiętych, przeniesione pule IP: :pools.', [
            'queued' => count($report['queued']), 'skipped' => count($report['skipped']), 'pools' => $report['pools'],
        ]));
    }
}
