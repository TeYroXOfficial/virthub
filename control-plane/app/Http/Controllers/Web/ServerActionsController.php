<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Models\IsoImage;
use App\Models\OsTemplate;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reinstalacja systemu i płyty ISO z poziomu strony maszyny. */
class ServerActionsController extends Controller
{
    public function __construct(private readonly ServerProvisioner $provisioner) {}

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
     * Stan do odpytywania przez stronę maszyny: ekran postępu reinstalacji
     * i tworzenia odświeża stronę, gdy operacja się skończy.
     */
    public function status(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        $job = $server->jobs()->reorder()->latest('id')->first();

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
            ] : null,
        ]);
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
