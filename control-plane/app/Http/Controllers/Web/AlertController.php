<?php

namespace App\Http\Controllers\Web;

use App\Domain\Metrics\Alerts;
use App\Http\Controllers\Controller;
use App\Models\AlertIncident;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Infrastruktura → Alerty: reguły progów, otwarte alerty i historia. */
class AlertController extends Controller
{
    public function index(): View
    {
        return view('panel.admin.alerts', [
            'rules' => AlertRule::query()->with('hypervisor')
                ->withCount(['incidents as open_count' => fn ($q) => $q->whereNull('resolved_at')])
                ->orderBy('name')->get(),
            'open' => AlertIncident::query()->with('rule')->whereNull('resolved_at')->latest('started_at')->get(),
            'history' => AlertIncident::query()->with('rule')->whereNotNull('resolved_at')->latest('resolved_at')->limit(50)->get(),
            'metrics' => Alerts::metrics(),
            'hypervisors' => Hypervisor::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = AlertRule::create($this->validated($request));
        AuditLog::record('alert_rule.created', $rule, ['name' => $rule->name, 'metric' => $rule->metric]);

        return back()->with('status', __('Reguła alertu dodana.'));
    }

    public function update(Request $request, AlertRule $rule): RedirectResponse
    {
        $rule->update($this->validated($request));
        AuditLog::record('alert_rule.updated', $rule, ['name' => $rule->name]);

        return back()->with('status', __('Reguła alertu zapisana.'));
    }

    public function destroy(AlertRule $rule): RedirectResponse
    {
        AuditLog::record('alert_rule.deleted', $rule, ['name' => $rule->name]);
        $rule->delete();

        return back()->with('status', __('Reguła alertu usunięta.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'metric' => ['required', Rule::in(array_keys(Alerts::metrics()))],
            'threshold' => ['required', 'integer', 'min:0', 'max:100000'],
            'duration_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'hypervisor_id' => ['nullable', 'integer', 'exists:hypervisors,id'],
        ]);

        return ['hypervisor_id' => null, ...$data] + [
            'notify_email' => $request->boolean('notify_email'),
            'notify_webhook' => $request->boolean('notify_webhook'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
