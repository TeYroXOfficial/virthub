<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\BackupPlan;
use App\Models\BackupTarget;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Infrastruktura → Kopie zapasowe: cele (katalog, S3, SFTP), plany i ostatnie kopie. */
class BackupAdminController extends Controller
{
    public function index(): View
    {
        return view('panel.admin.backups', [
            'targets' => BackupTarget::query()->withCount('plans')->orderBy('name')->get(),
            'plans' => BackupPlan::query()->with('target')->withCount('servers')->orderBy('name')->get(),
            'recent' => Backup::query()->where('type', Backup::TYPE_REMOTE)->with(['server:id,hostname', 'target:id,name'])->latest('id')->limit(50)->get(),
        ]);
    }

    public function storeTarget(Request $request): RedirectResponse
    {
        $target = BackupTarget::create($this->targetData($request));
        AuditLog::record('backup_target.created', $target, ['name' => $target->name, 'type' => $target->type]);

        return back()->with('status', __('Dodano cel kopii :name.', ['name' => $target->name]));
    }

    public function updateTarget(Request $request, BackupTarget $target): RedirectResponse
    {
        $data = $this->targetData($request, $target);
        $target->update($data);
        AuditLog::record('backup_target.updated', $target, ['name' => $target->name]);

        return back()->with('status', __('Zapisano cel kopii :name.', ['name' => $target->name]));
    }

    public function destroyTarget(BackupTarget $target): RedirectResponse
    {
        if ($target->plans()->exists()) {
            return back()->withErrors(['target' => __('Cel jest używany przez plany kopii — zmień je najpierw.')]);
        }
        AuditLog::record('backup_target.deleted', $target, ['name' => $target->name]);
        $target->delete();

        return back()->with('status', __('Cel kopii usunięty (pliki w nim zostają).'));
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $plan = BackupPlan::create($this->planData($request));
        AuditLog::record('backup_plan.created', $plan, ['name' => $plan->name]);

        return back()->with('status', __('Dodano plan kopii :name.', ['name' => $plan->name]));
    }

    public function updatePlan(Request $request, BackupPlan $plan): RedirectResponse
    {
        $plan->update($this->planData($request));
        AuditLog::record('backup_plan.updated', $plan, ['name' => $plan->name]);

        return back()->with('status', __('Zapisano plan kopii :name.', ['name' => $plan->name]));
    }

    public function destroyPlan(BackupPlan $plan): RedirectResponse
    {
        AuditLog::record('backup_plan.deleted', $plan, ['name' => $plan->name, 'servers' => $plan->servers()->count()]);
        $plan->delete();

        return back()->with('status', __('Plan kopii usunięty — maszyny przestaną robić kopie według niego.'));
    }

    /** Plan kopii maszyny (strona maszyny → Ustawienia, personel). */
    public function assign(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('manageResources', $server);
        $id = $request->validate(['backup_plan_id' => ['nullable', 'integer', 'exists:backup_plans,id']])['backup_plan_id'] ?? null;
        $server->forceFill(['backup_plan_id' => $id])->save();
        AuditLog::record('server.backup_plan', $server, ['plan_id' => $id]);

        return redirect()->to(route('panel.servers.show', $server).'#remote-backups')->with('status', __('Zapisano plan kopii maszyny.'));
    }

    /** @return array<string, mixed> */
    private function targetData(Request $request, ?BackupTarget $existing = null): array
    {
        $type = $existing?->type ?? $request->input('type');
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'type' => [$existing ? 'nullable' : 'required', Rule::in(BackupTarget::TYPES)],
        ];
        $fields = match ($type) {
            's3' => ['endpoint' => ['required', 'url:https,http'], 'region' => ['nullable', 'string', 'max:40'], 'bucket' => ['required', 'string', 'max:63'],
                'prefix' => ['nullable', 'string', 'max:200', 'regex:#^[A-Za-z0-9._/-]*$#'], 'access_key' => ['required', 'string', 'max:200'], 'secret_key' => [$existing ? 'nullable' : 'required', 'string', 'max:200']],
            'sftp' => ['host' => ['required', 'string', 'max:253'], 'port' => ['nullable', 'integer', 'min:1', 'max:65535'], 'username' => ['required', 'string', 'max:100'],
                'password' => ['nullable', 'string', 'max:200'], 'private_key' => ['nullable', 'string', 'max:10000'], 'path' => ['required', 'string', 'max:500', 'regex:#^/[A-Za-z0-9._/-]*$#'],
                'host_key_fingerprint' => ['nullable', 'string', 'max:100', 'regex:/^SHA256:[A-Za-z0-9+\/=]+$/']],
            default => ['path' => ['required', 'string', 'max:500', 'regex:#^/[A-Za-z0-9._/-]+$#', 'not_regex:#(^|/)\.\.(/|$)#']],
        };
        foreach ($fields as $field => $rule) {
            $rules['config.'.$field] = $rule;
        }
        $data = $request->validate($rules);
        $config = array_filter((array) ($data['config'] ?? []), fn ($v) => $v !== null && $v !== '');
        // Puste pola sekretów przy edycji = bez zmiany.
        if ($existing) {
            $config = $config + array_intersect_key((array) $existing->config, array_flip(['secret_key', 'password', 'private_key']));
        }

        return ['name' => $data['name'], 'type' => $type, 'config' => $config, 'is_active' => $request->boolean('is_active', true)];
    }

    /** @return array<string, mixed> */
    private function planData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'backup_target_id' => ['required', 'integer', 'exists:backup_targets,id'],
            'interval_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'hour' => ['required', 'integer', 'min:0', 'max:23'],
            'retention' => ['required', 'integer', 'min:1', 'max:365'],
            'full_every' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
        $data['full_every'] = (int) ($data['full_every'] ?? 7);

        return $data + ['compress' => $request->boolean('compress'), 'is_active' => $request->boolean('is_active'), 'incremental' => $request->boolean('incremental')];
    }
}
