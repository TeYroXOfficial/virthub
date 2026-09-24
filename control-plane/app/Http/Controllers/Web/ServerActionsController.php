<?php

namespace App\Http\Controllers\Web;

use App\Domain\Provisioning\ServerProvisioner;
use App\Http\Controllers\Controller;
use App\Models\IsoImage;
use App\Models\OsTemplate;
use App\Models\Server;
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
            // Kasuje dysk bezpowrotnie — wpisanie nazwy hosta to świadome potwierdzenie.
            'confirm_hostname' => ['required', 'string'],
        ], [
            'ssh_key.regex' => 'Klucz SSH musi być w formacie OpenSSH (ssh-ed25519 AAAA… lub ssh-rsa AAAA…).',
        ]);

        if (mb_strtolower(trim($validated['confirm_hostname'])) !== mb_strtolower($server->hostname)) {
            return back()->withErrors(['confirm_hostname' => 'Wpisana nazwa hosta nie zgadza się — reinstalacja nie została uruchomiona.'])
                ->withInput();
        }

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

        return redirect()->route('panel.servers.show', $server)
            ->with('status', 'Trwa reinstalacja systemu. Nowe hasło roota pojawi się poniżej — zapisz je.');
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
