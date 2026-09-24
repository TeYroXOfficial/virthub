<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Domain\Updates\Updates;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Administracja → Aktualizacje: wersja panelu i agentów, zlecanie aktualizacji.
 */
class UpdatesController extends Controller
{
    public function __construct(private readonly Updates $updates) {}

    public function index(Request $request): View
    {
        return view('panel.admin.updates', [
            'panelVersion' => $this->updates->panelVersion(),
            'latest' => $this->updates->latest($request->boolean('refresh')),
            'panelEnabled' => $this->updates->panelUpdatesEnabled(),
            'panelStatus' => $this->updates->panelStatus(),
            'nodes' => Hypervisor::query()->whereNotNull('enrolled_at')->orderBy('name')->get(),
        ]);
    }

    public function updatePanel(): RedirectResponse
    {
        try {
            $this->updates->requestPanelUpdate();
        } catch (\RuntimeException $e) {
            return back()->withErrors(['update' => $e->getMessage()]);
        }

        AuditLog::record('panel.update_requested', null, ['target' => $this->updates->latest()['sha'] ?? null]);

        return back()->with('status', 'Zlecono aktualizację panelu. Potrwa kilka minut — w tym czasie panel może chwilowo nie odpowiadać.');
    }

    public function updateNode(Hypervisor $hypervisor): RedirectResponse
    {
        $error = $this->requestNodeUpdate($hypervisor);

        return $error === null
            ? back()->with('status', "Zlecono aktualizację węzła {$hypervisor->name}.")
            : back()->withErrors(['update' => $error]);
    }

    /** Aktualizuje wszystkie węzły, które nie mają najnowszej wersji. */
    public function updateAllNodes(): RedirectResponse
    {
        $latest = $this->updates->latest()['sha'] ?? null;
        $requested = [];
        $errors = [];

        foreach (Hypervisor::query()->whereNotNull('enrolled_at')->get() as $node) {
            if ($latest !== null && ($node->last_health['build'] ?? null) === $latest) {
                continue;
            }

            $error = $this->requestNodeUpdate($node);
            $error === null ? $requested[] = $node->name : $errors[] = $error;
        }

        $redirect = back();

        if ($requested !== []) {
            $redirect = $redirect->with('status', 'Zlecono aktualizację węzłów: '.implode(', ', $requested).'.');
        } elseif ($errors === []) {
            $redirect = $redirect->with('status', 'Wszystkie węzły mają już najnowszą wersję.');
        }

        return $errors === [] ? $redirect : $redirect->withErrors($errors);
    }

    /** Stan dla odświeżania strony w tle: panel i każdy węzeł na żywo. */
    public function status(): JsonResponse
    {
        $nodes = Hypervisor::query()->whereNotNull('enrolled_at')->orderBy('name')->get()
            ->map(function (Hypervisor $node) {
                try {
                    $status = (new AgentClient($node))->updateStatus();
                } catch (AgentException $e) {
                    $status = ['state' => 'unreachable', 'message' => 'Węzeł nie odpowiada.'];
                }

                return ['id' => $node->id, ...$status];
            });

        return response()->json([
            'panel' => [...$this->updates->panelStatus(), 'version' => $this->updates->panelVersion()],
            'latest' => $this->updates->latest()['sha'] ?? null,
            'nodes' => $nodes->values(),
        ]);
    }

    private function requestNodeUpdate(Hypervisor $node): ?string
    {
        try {
            (new AgentClient($node))->requestUpdate();
        } catch (AgentException $e) {
            return $e->status === 404
                ? "Węzeł {$node->name} ma agenta sprzed zdalnych aktualizacji — zaktualizuj go raz ręcznie (update-node.sh)."
                : $e->getMessage();
        }

        AuditLog::record('hypervisor.update_requested', $node, ['name' => $node->name]);

        return null;
    }
}
