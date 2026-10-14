<?php

namespace App\Http\Controllers\Web;

use App\Domain\Billing\Billing;
use App\Domain\Provisioning\SelfService;
use App\Http\Controllers\Controller;
use App\Models\HypervisorGroup;
use App\Models\OsTemplate;
use App\Models\ResourcePack;
use App\Models\Server;
use App\Rules\SshPublicKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Samoobsługa klienta: budowanie i usuwanie maszyn w ramach pakietu zasobów. */
class SelfServiceController extends Controller
{
    public function __construct(private readonly SelfService $selfService) {}

    public function index(Request $request): View
    {
        $packs = $request->user()->resourcePacks()->with(['package', 'servers.template'])->where('is_active', true)->get();
        abort_if($packs->isEmpty(), 404);

        return view('panel.self-service', [
            'packs' => $packs,
            'templates' => OsTemplate::query()->active()->where('virtualization', 'kvm')->orderBy('name')->orderByDesc('version')->get()
                ->filter(fn (OsTemplate $t) => $t->isSelfService())->values(),
            'locations' => HypervisorGroup::query()->where('is_public', true)->where('accepts_new_servers', true)
                ->whereHas('hypervisors', fn ($q) => $q->available())->ordered()->get(),
            'billing' => Billing::enabled(),
        ]);
    }

    public function store(Request $request, ResourcePack $pack): RedirectResponse
    {
        abort_unless($pack->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'template' => ['required', 'integer', 'exists:os_templates,id'],
            'hostname' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i'],
            'vcpu' => ['required', 'integer', 'min:1', 'max:128'],
            'ram_mb' => ['required', 'integer', 'min:'.SelfService::MIN_RAM_MB, 'max:1048576'],
            'disk_gb' => ['required', 'integer', 'min:1', 'max:65536'],
            'location' => ['nullable', 'integer', 'exists:hypervisor_groups,id'],
            'ssh_key' => ['nullable', 'string', 'max:1000', new SshPublicKey],
        ]);
        $template = OsTemplate::query()->active()->findOrFail($data['template']);
        abort_unless($template->isSelfService(), 422);
        $location = isset($data['location']) ? HypervisorGroup::query()->where('is_public', true)->find($data['location']) : null;

        $server = $this->selfService->build($pack, $template, Str::lower($data['hostname']), (int) $data['vcpu'], (int) $data['ram_mb'], (int) $data['disk_gb'],
            array_filter([trim((string) ($data['ssh_key'] ?? ''))]), $location, $request->user());

        return redirect()->route('panel.servers.show', $server)->with('status', __('Maszyna jest tworzona. Zwykle trwa to około minuty.'));
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        abort_unless($server->user_id === $request->user()->id, 404);
        $this->selfService->destroy($server, $request->user());

        return redirect()->route('panel.self-service')->with('status', __('Maszyna :hostname jest usuwana — jej zasoby wrócą do pakietu.', ['hostname' => $server->hostname]));
    }
}
