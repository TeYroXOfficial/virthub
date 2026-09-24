<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Provisioning\AgentResultApplier;
use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Models\IsoImage;
use App\Models\OsTemplate;
use App\Models\Server;
use App\Models\ServerJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'ssh_key' => ['nullable', 'string', 'max:1000', 'regex:/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp[0-9]+)\s+[A-Za-z0-9+\/=]+/'],
            // Kasuje dysk bezpowrotnie — wymagamy świadomego potwierdzenia.
            'confirm' => ['accepted'],
        ], [
            'ssh_key.regex' => 'Klucz SSH musi być w formacie OpenSSH (ssh-ed25519 AAAA… lub ssh-rsa AAAA…).',
            'confirm.accepted' => 'Potwierdź, że rozumiesz, że dane na dysku zostaną usunięte.',
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

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('destroy', $server);
        $request->validate(['confirm' => ['accepted']], [
            'confirm.accepted' => 'Potwierdź, że maszyna ma zostać usunięta razem z dyskiem.',
        ]);

        if ($server->state === \App\Enums\ServerState::Deleting) {
            return back()->withErrors(['delete' => 'Maszyna jest już usuwana. Jeśli to trwa zbyt długo, administrator może usunąć ją tylko z panelu.']);
        }

        $this->provisioner->destroy($server, $request->user());

        return $this->afterDelete($request, $server, "Maszyna {$server->hostname} jest usuwana.");
    }

    public function purge(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('purge', $server);
        $request->validate(['confirm' => ['accepted']], [
            'confirm.accepted' => 'Potwierdź usunięcie wpisu z panelu.',
        ]);

        $this->provisioner->purge($server, $request->user());

        return $this->afterDelete($request, $server, "Usunięto {$server->hostname} z panelu. Adresy IP i zasoby węzła są wolne.");
    }

    private function afterDelete(Request $request, Server $server, string $message): RedirectResponse
    {
        $route = $server->user_id !== $request->user()->id && $request->user()->isStaff()
            ? 'panel.admin.servers'
            : 'panel.dashboard';

        return redirect()->route($route)->with('status', $message);
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
            ->with('status', 'Zmieniam hasło roota — nowe pojawi się na tej stronie za kilka sekund.');
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

    public function iso(Request $request, Server $server): RedirectResponse
    {
        $this->authorize('iso', $server);

        $validated = $request->validate([
            'iso' => ['nullable', 'integer', Rule::exists(IsoImage::class, 'id')],
        ]);

        $iso = ! empty($validated['iso']) ? IsoImage::findOrFail($validated['iso']) : null;

        // Obraz ukryty jest tylko dla personelu.
        abort_if($iso !== null && ! $iso->is_public && ! $request->user()->isStaff(), 403);

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
            ? 'Płyta zostanie wysunięta.'
            : "Montuję {$iso->name}.".($request->boolean('restart') ? ' Maszyna zostanie uruchomiona ponownie.' : ' Zmiana rozruchu zadziała po wyłączeniu i włączeniu maszyny.');

        return redirect()->to(route('panel.servers.show', $server).'#iso')->with('status', $message);
    }
}
