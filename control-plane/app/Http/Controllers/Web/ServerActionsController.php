<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Metrics\Traffic;
use App\Domain\Network\ReverseDns;
use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\GuestOs;
use App\Domain\Provisioning\IsoLibrary;
use App\Domain\Provisioning\Migrations;
use App\Domain\Provisioning\RemoteBackups;
use App\Domain\Provisioning\ServerProvisioner;
use App\Enums\ServerState;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Hypervisor;
use App\Models\IpAddress;
use App\Models\IsoImage;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use App\Models\VpsPackage;
use App\Rules\SshPublicKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/** Reinstalacja systemu i płyty ISO z poziomu strony maszyny. */
class ServerActionsController extends Controller
{
    public function __construct(
        private readonly ServerProvisioner $provisioner,
        private readonly AgentResultApplier $results,
    ) {}

    public function rebuild(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('rebuild', $server);

        $validated = $request->validate([
            'template' => ['required', Rule::exists(OsTemplate::class, 'id')->where('is_active', true)],
            'ssh_key' => ['nullable', 'string', 'max:1000', new SshPublicKey],
            // Kasuje dysk bezpowrotnie — wymagamy świadomego potwierdzenia.
            'confirm' => ['accepted'],
        ], [
            'ssh_key.regex' => __('Klucz SSH musi być w formacie OpenSSH (ssh-ed25519 AAAA… lub ssh-rsa AAAA…).'),
            'confirm.accepted' => __('Potwierdź, że rozumiesz, że dane na dysku zostaną usunięte.'),
        ]);

        try {
            $this->provisioner->rebuild(
                $server,
                OsTemplate::findOrFail($validated['template']),
                array_filter([trim((string) ($validated['ssh_key'] ?? ''))]),
                $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['template' => $e->getMessage()]);
        }

        return redirect()->route('panel.servers.show', $server);
    }

    /**
     * Zmiana pakietu maszyny (wymaga zatrzymanej maszyny). Personel może wybrać
     * także obecny pakiet — tak po edycji pakietu przenosi się jego nowe parametry.
     */
    public function resize(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('resize', $server);
        $validated = $request->validate(['package' => ['required', 'integer', Rule::exists(VpsPackage::class, 'id')]]);
        $package = VpsPackage::query()->findOrFail($validated['package']);
        $user = $request->user();

        $allowed = $user->isStaff()
            ? ($package->is_active || $package->id === $server->vps_package_id)
            : ($package->is_active && $user->mayOrderPackage($package));
        if (! $allowed) {
            return back()->withErrors(['package' => __('Pakiet :name nie jest dostępny.', ['name' => $package->name])]);
        }

        try {
            $this->provisioner->resize($server, $package, $user);
        } catch (\DomainException $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        return redirect()->route('panel.servers.show', $server)->with('status', __('Zmiana pakietu zlecona — maszyna dostanie parametry pakietu :name.', ['name' => $package->name]));
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('destroy', $server);
        $request->validate(['confirm' => ['accepted']], [
            'confirm.accepted' => __('Potwierdź, że maszyna ma zostać usunięta razem z dyskiem.'),
        ]);

        if ($server->state === ServerState::Deleting) {
            return back()->withErrors(['delete' => __('Maszyna jest już usuwana. Jeśli to trwa zbyt długo, administrator może usunąć ją tylko z panelu.')]);
        }

        $this->provisioner->destroy($server, $request->user());

        return $this->afterDelete($request, $server, __('Maszyna :hostname jest usuwana.', ['hostname' => $server->hostname]));
    }

    public function purge(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('purge', $server);
        $request->validate(['confirm' => ['accepted']], [
            'confirm.accepted' => __('Potwierdź usunięcie wpisu z panelu.'),
        ]);

        $this->provisioner->purge($server, $request->user());

        return $this->afterDelete($request, $server, __('Usunięto :hostname z panelu. Adresy IP i zasoby węzła są wolne.', ['hostname' => $server->hostname]));
    }

    private function afterDelete(Request $request, Server $server, string $message): RedirectResponse
    {
        $route = $server->user_id !== $request->user()->id && $request->user()->isStaff()
            ? 'panel.admin.servers'
            : 'panel.dashboard';

        return redirect()->route($route)->with('status', $message);
    }

    /** Personel: limit transferu maszyny albo wyzerowanie licznika. */
    public function traffic(Request $request, Server $server, Traffic $traffic): RedirectResponse
    {
        $this->authorize('manageTraffic', $server);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['limit', 'reset', 'policy', 'block', 'unblock'])],
            'bandwidth_gb' => ['required_if:action,limit', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'traffic_counting' => ['nullable', Rule::in(Traffic::COUNTING)],
            'traffic_limit_action' => ['nullable', Rule::in(Traffic::ACTIONS)],
            'traffic_throttle_mbps' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'port_speed_mbps' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'gb' => ['required_if:action,block', 'nullable', 'integer', 'min:1', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'block_id' => ['required_if:action,unblock', 'nullable', 'integer'],
        ]);

        if ($validated['action'] === 'reset') {
            $traffic->reset($server, $request->user());
            $message = __('Licznik transferu wyzerowany.');
        } elseif ($validated['action'] === 'policy') {
            $policy = array_map(fn ($v) => $v === '' ? null : $v, array_intersect_key($validated + array_fill_keys(['traffic_counting', 'traffic_limit_action', 'traffic_throttle_mbps', 'port_speed_mbps'], null),
                array_flip(['traffic_counting', 'traffic_limit_action', 'traffic_throttle_mbps', 'port_speed_mbps'])));
            $speedChanged = $server->port_speed_mbps !== ($policy['port_speed_mbps'] === null ? null : (int) $policy['port_speed_mbps'])
                || $server->traffic_throttle_mbps !== ($policy['traffic_throttle_mbps'] === null ? null : (int) $policy['traffic_throttle_mbps']);
            $server->forceFill($policy)->save();
            AuditLog::record('server.traffic_policy', $server, $policy, $request->user());
            $traffic->releaseEligible($server);
            if ($speedChanged && $server->agent_uuid !== null && ! $server->isSuspended()) {
                app(ServerProvisioner::class)->syncNetwork($server, $request->user());
            }
            $message = __('Zapisano politykę transferu.');
        } elseif ($validated['action'] === 'block') {
            $traffic->addBlock($server, (int) $validated['gb'], $validated['note'] ?? null, $request->user());
            $message = __('Dodano pakiet transferu.');
        } elseif ($validated['action'] === 'unblock') {
            $traffic->removeBlock($server->trafficBlocks()->findOrFail($validated['block_id']), $request->user());
            $message = __('Usunięto pakiet transferu.');
        } else {
            $old = $server->bandwidth_gb;
            $server->forceFill(['bandwidth_gb' => (int) $validated['bandwidth_gb']])->save();
            AuditLog::record('server.traffic_limit', $server, ['from' => $old, 'to' => $server->bandwidth_gb], $request->user());
            $traffic->releaseEligible($server);
            $message = __('Zapisano limit transferu.');
        }

        return redirect()->to(route('panel.servers.show', $server).'#traffic-admin')->with('status', $message);
    }

    /** Personel: twardy limit procesora (procent jednego rdzenia; puste = bez limitu). */
    public function cpuLimit(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('manageResources', $server);

        $validated = $request->validate([
            'cpu_limit_percent' => ['nullable', 'integer', 'min:1', 'max:'.($server->vcpu * 100)],
        ], [
            'cpu_limit_percent.max' => __('Limit nie może przekraczać :max% (:vcpu vCPU × 100%).', ['max' => $server->vcpu * 100, 'vcpu' => $server->vcpu]),
        ]);

        try {
            $this->provisioner->setCpuLimit($server, $validated['cpu_limit_percent'] ?? null, $request->user());
        } catch (\DomainException $e) {
            return redirect()->to(route('panel.servers.show', $server).'#cpu-limit')->withErrors(['cpu_limit_percent' => $e->getMessage()]);
        }

        return redirect()->to(route('panel.servers.show', $server).'#cpu-limit')
            ->with('status', __('Limit procesora trafi na węzeł w ciągu kilku sekund — bez restartu maszyny.'));
    }

    /** Personel: migracja maszyny na inny hypervisor (na zimno albo na żywo). */
    public function migrate(Request $request, Server $server, Migrations $migrations): RedirectResponse
    {
        $this->authorize('manageResources', $server);
        $data = $request->validate([
            'hypervisor_id' => ['required', 'integer', 'exists:hypervisors,id'],
            'mode' => ['required', Rule::in(Migrations::MODES)],
        ]);
        try {
            $migrations->start($server, Hypervisor::findOrFail($data['hypervisor_id']), $data['mode'], $request->user());
        } catch (\DomainException $e) {
            return redirect()->to(route('panel.servers.show', $server).'#migrate')->withErrors(['migrate' => $e->getMessage()]);
        }

        return redirect()->route('panel.servers.show', $server)->with('status', __('Migracja rozpoczęta — postęp widać na stronie maszyny.'));
    }

    /** Personel: anulowanie trwającej, wznowienie albo cofnięcie nieudanej migracji. */
    public function migrationAction(Request $request, Server $server, string $action, Migrations $migrations): RedirectResponse
    {
        $this->authorize('manageResources', $server);
        $back = redirect()->to(route('panel.servers.show', $server).'#migrate');
        $job = $migrations->latest($server);
        if ($job === null) {
            return $back->withErrors(['migrate' => __('Ta maszyna nie była migrowana.')]);
        }
        try {
            match ($action) {
                'cancel' => $migrations->cancel($job, $request->user()),
                'resume' => $migrations->resume($job, $request->user()),
                'revert' => $migrations->revert($job, $request->user()),
            };
        } catch (\DomainException $e) {
            return $back->withErrors(['migrate' => $e->getMessage()]);
        }

        return $back->with('status', [
            'cancel' => __('Anulowanie migracji — maszyna wróci do pracy na węźle źródłowym, gdy bieżący krok się zakończy.'),
            'resume' => __('Migracja wznowiona.'),
            'revert' => __('Migracja cofnięta — pozostałości na węźle docelowym są usuwane.'),
        ][$action]);
    }

    /** rDNS adresu maszyny — klient ustawia nazwę, która wskazuje na ten adres. */
    public function rdns(Request $request, Server $server, IpAddress $address, ReverseDns $reverseDns): RedirectResponse
    {
        $this->authorize('operate', $server);
        abort_unless($address->server_id === $server->id, 404);

        $back = redirect()->to(route('panel.servers.show', $server).'#network');
        if ($address->isNat()) {
            return $back->withErrors(['rdns' => __('Adres za NAT-em jest prywatny — rDNS ustawia się dla publicznego adresu węzła.')]);
        }

        $data = $request->validate(['rdns' => ['nullable', 'string', 'max:253']]);
        $reverseDns->set($address, $data['rdns'] ?? null, $request->user(), asStaff: $request->user()->isStaff());

        return $back->with('status', ($data['rdns'] ?? null)
            ? __('rDNS :address → :host zapisany.', ['address' => $address->address, 'host' => $address->fresh()->rdns])
            : __('rDNS adresu :address usunięty.', ['address' => $address->address]));
    }

    /** Odczyt systemu z wnętrza maszyny na żądanie (normalnie co kilka godzin). */
    public function detectOs(Server $server, GuestOs $guestOs): RedirectResponse
    {
        $this->authorize('operate', $server);

        $ok = $guestOs->detect($server->loadMissing('hypervisor'));

        return redirect()->to(route('panel.servers.show', $server).'#settings')->with('status', $ok
            ? 'System w maszynie: '.$server->fresh()->guest_os_name.'.'
            : ($server->isContainer()
                ? __('Nie udało się odczytać systemu kontenera — sprawdź, czy węzeł ma aktualnego agenta.')
                : __('Nie udało się odczytać systemu — maszyna musi działać i mieć uruchomiony qemu-guest-agent.')));
    }

    public function resetPassword(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('resetPassword', $server);

        try {
            $this->provisioner->resetPassword($server, $request->user());
        } catch (\DomainException $e) {
            return redirect()->to(route('panel.servers.show', $server).'#password')->withErrors(['password' => $e->getMessage()]);
        }

        return redirect()->to(route('panel.servers.show', $server).'#password')
            ->with('status', __('Zmieniam hasło roota — nowe pojawi się na tej stronie za kilka sekund.'));
    }

    /**
     * Stan do odpytywania przez stronę maszyny. Dla trwającej operacji pyta
     * węzeł o bieżący etap (kopiowanie obrazu, sieć, uruchamianie…), żeby
     * ekran postępu pokazywał to, co naprawdę dzieje się na hypervisorze.
     */
    public function status(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        $job = $server->jobs()->reorder()->latest('id')->first();
        $agent = $job ? $this->agentJobState($server, $job) : null;

        if ($agent !== null && in_array($agent['status'] ?? null, ['done', 'failed'], true) && ! $job->isFinished()) {
            // Węzeł skończył, a callback jeszcze nie dotarł — stosujemy wynik
            // od razu (operacja jest idempotentna), zamiast czekać na uzgadnianie.
            $this->results->apply($job, $agent);
            $server->refresh();
            $job->refresh();
        }

        // Migracja prowadzi własne zadania na dwóch węzłach — etap i postęp są w jej payloadzie.
        if ($job?->action === 'migrate' && ! $job->isFinished()) {
            $p = $job->payload;
            $stage = match ($p['phase'] ?? 'start') {
                'start' => 'pending', 'exporting', 'preparing' => 'export', 'migrating' => 'download',
                default => in_array($p['progress']['stage'] ?? null, ['boot', 'network'], true) ? 'boot' : 'download',
            };
            $agent = ['stage' => $stage, 'progress' => Migrations::overall($job), 'detail' => $p['progress']['detail'] ?? null];
        }

        return response()->json([
            'state' => $server->state->value,
            'state_label' => $server->state->label(),
            'transitioning' => $server->state->isTransitioning(),
            'progress' => (int) $server->build_progress,
            'job' => $job ? [
                'id' => $job->id,
                'action' => $job->action,
                'status' => $job->status,
                'finished' => $job->isFinished(),
                'error' => $job->status === 'failed' ? $job->error : null,
                'elapsed' => (int) $job->created_at->diffInSeconds(now(), true),
                // Etap na węźle: null, dopóki zadanie czeka w kolejce panelu.
                'stage' => $job->isFinished() ? null : ($agent['stage'] ?? ($job->agent_job_id ? 'queued' : 'pending')),
                'stage_progress' => $job->isFinished() ? 100 : ($agent['progress'] ?? null),
                'stage_detail' => $job->isFinished() ? null : ($agent['detail'] ?? null),
            ] : null,
        ]);
    }

    /** @return array<string, mixed>|null stan zadania na węźle (cache 2 s — stronę może oglądać kilka osób) */
    private function agentJobState(Server $server, ServerJob $job): ?array
    {
        if ($job->isFinished() || ! $job->agent_job_id || ! $server->hypervisor) {
            return null;
        }

        return Cache::remember("agent-job:{$job->agent_job_id}", now()->addSeconds(2), function () use ($server, $job) {
            try {
                return (new AgentClient($server->hypervisor))->job($job->agent_job_id);
            } catch (AgentException) {
                return null; // węzeł chwilowo nie odpowiada — pokażemy postęp szacowany
            }
        });
    }

    public function createRemoteBackup(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('snapshots', $server);
        $plan = $server->backupPlan()->with('target')->first();
        abort_if($plan === null || $plan->target === null, 404);
        try {
            app(RemoteBackups::class)->create($server, $plan->target, $plan->compress, $request->user(), $plan);
        } catch (\DomainException $e) {
            return redirect()->to(route('panel.servers.show', $server).'#remote-backups')->withErrors(['backup' => $e->getMessage()]);
        }

        return redirect()->to(route('panel.servers.show', $server).'#remote-backups')->with('status', __('Kopia jest tworzona — maszyna działa dalej.'));
    }

    public function restoreRemoteBackup(Request $request, Server $server, Backup $backup): RedirectResponse
    {
        $this->authorize('restoreSnapshot', $server);
        abort_unless($backup->server_id === $server->id && $backup->isRemote(), 404);
        try {
            app(RemoteBackups::class)->restore($server, $backup, $request->user());
        } catch (\DomainException $e) {
            return redirect()->to(route('panel.servers.show', $server).'#remote-backups')->withErrors(['backup' => $e->getMessage()]);
        }

        return redirect()->to(route('panel.servers.show', $server).'#remote-backups')->with('status', __('Przywracam kopię :name — maszyna uruchomi się ponownie.', ['name' => $backup->name]));
    }

    public function deleteRemoteBackup(Request $request, Server $server, Backup $backup): RedirectResponse
    {
        $this->authorize('snapshots', $server);
        abort_unless($backup->server_id === $server->id && $backup->isRemote(), 404);
        app(RemoteBackups::class)->delete($backup);

        return redirect()->to(route('panel.servers.show', $server).'#remote-backups')->with('status', __('Kopia usunięta.'));
    }

    public function elevate(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('snapshots', $server);
        $action = $request->validate(['action' => ['required', Rule::in(['start', 'commit', 'discard'])]])['action'];
        try {
            $this->provisioner->elevate($server, $action, $request->user());
        } catch (\DomainException $e) {
            return redirect()->to(route('panel.servers.show', $server).'#elevate')->withErrors(['elevate' => $e->getMessage()]);
        }

        return redirect()->to(route('panel.servers.show', $server).'#elevate')->with('status', match ($action) {
            'start' => __('Włączam Elevate — od teraz zmiany dysku trafiają do nakładki.'),
            'commit' => __('Zapisuję zmiany z nakładki na dysk — może to chwilę potrwać.'),
            default => __('Odrzucam zmiany — maszyna wróci do stanu sprzed Elevate.'),
        });
    }

    public function storeCustomIso(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('iso', $server);
        $data = $request->validate([
            'custom_name' => ['required', 'string', 'max:100'],
            'custom_url' => ['required', 'url:http,https', 'max:2000'],
        ]);
        app(IsoLibrary::class)->addCustom($server, $data['custom_name'], $data['custom_url'], $request->user());

        return redirect()->to(route('panel.servers.show', $server).'#iso')
            ->with('status', __('Węzeł pobiera obraz — po zakończeniu pojawi się na liście płyt.'));
    }

    public function destroyCustomIso(Request $request, Server $server, IsoImage $iso): RedirectResponse
    {
        $this->authorize('iso', $server);
        abort_unless($iso->server_id === $server->id, 404);
        app(IsoLibrary::class)->delete($iso, $request->user());

        return redirect()->to(route('panel.servers.show', $server).'#iso')->with('status', __('Obraz usunięty.'));
    }

    public function iso(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('iso', $server);

        $validated = $request->validate([
            'iso' => ['nullable', 'integer', Rule::exists(IsoImage::class, 'id')],
        ]);

        $iso = ! empty($validated['iso']) ? IsoImage::findOrFail($validated['iso']) : null;

        // Obraz ukryty jest tylko dla personelu.
        abort_if($iso !== null && ! $iso->is_public && ! $request->user()->isStaff() && $iso->server_id !== $server->id, 403);
        // Własny obraz innej maszyny — nigdy.
        abort_if($iso !== null && $iso->isCustom() && $iso->server_id !== $server->id, 403);

        try {
            $this->provisioner->mountIso(
                $server,
                $iso,
                $request->boolean('boot'),
                $request->boolean('restart'),
                $request->user(),
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['iso' => $e->getMessage()]);
        }

        $message = $iso === null
            ? __('Płyta zostanie wysunięta.')
            : __('Montuję :name.', ['name' => $iso->name]).($request->boolean('restart') ? __(' Maszyna zostanie uruchomiona ponownie.') : __(' Zmiana rozruchu zadziała po wyłączeniu i włączeniu maszyny.'));

        return redirect()->to(route('panel.servers.show', $server).'#iso')->with('status', $message);
    }
}
