<?php

namespace App\Http\Controllers\Web;

use App\Domain\External\ExternalServerManager;
use App\Domain\External\ProviderException;
use App\Http\Controllers\Controller;
use App\Models\ExternalServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/** Strona VPS-a u dostawcy zewnętrznego — dla klienta wygląda jak zwykły VPS. */
class CloudServerController extends Controller
{
    public function __construct(private readonly ExternalServerManager $manager) {}

    public function show(ExternalServer $server): View
    {
        $this->authorize('view', $server);
        if (! in_array($server->status, [ExternalServer::DELETED], true)
            && ($server->synced_at === null || $server->synced_at->lt(now()->subSeconds(30)))) {
            rescue(fn () => $this->manager->sync($server), report: false);
        }

        return view('panel.cloud.show', [
            'server' => $server->fresh(),
            'capabilities' => $this->capabilities($server),
            'images' => $this->images($server),
        ]);
    }

    /** Stan dla automatycznego odświeżania strony w trakcie tworzenia i operacji. */
    public function status(ExternalServer $server): JsonResponse
    {
        $this->authorize('view', $server);
        if (in_array($server->status, ExternalServer::TRANSITIONAL, true)) {
            rescue(fn () => $this->manager->sync($server), report: false);
            $server->refresh();
        }

        return response()->json(['status' => $server->status, 'label' => $server->statusLabel(), 'ipv4' => $server->ipv4]);
    }

    public function power(Request $request, ExternalServer $server): RedirectResponse
    {
        $this->authorize('power', $server);
        $data = $request->validate(['action' => ['required', Rule::in(['start', 'stop', 'reboot', 'kill'])]]);

        return $this->run(fn () => $this->manager->power($server, $data['action'], $request->user()),
            __('Polecenie wysłane — stan odświeży się za chwilę.'));
    }

    public function reinstall(Request $request, ExternalServer $server): RedirectResponse
    {
        $this->authorize('rebuild', $server);
        $images = collect($this->images($server));
        $data = $request->validate([
            'image' => ['required', 'string', Rule::in($images->pluck('id')->all())],
            'confirm' => ['required', 'in:'.$server->hostname],
        ], ['confirm.in' => __('Wpisz nazwę hosta, żeby potwierdzić reinstalację.')]);

        return $this->run(fn () => $this->manager->reinstall($server, $data['image'], $images->firstWhere('id', $data['image'])['name'] ?? null, $request->user()),
            __('Reinstalacja rozpoczęta. Nowe hasło root pojawi się tutaj po zakończeniu.'));
    }

    public function console(Request $request, ExternalServer $server): RedirectResponse
    {
        $this->authorize('console', $server);
        try {
            return redirect()->away($this->manager->console($server, $request->user()));
        } catch (\DomainException|ProviderException $e) {
            return back()->withErrors(['server' => $e->getMessage()]);
        }
    }

    public function rdns(Request $request, ExternalServer $server): RedirectResponse
    {
        $this->authorize('operate', $server);
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'hostname' => ['nullable', 'string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'],
        ]);

        return $this->run(fn () => $this->manager->rdns($server, $data['ip'], $data['hostname'] ?: null, $request->user()),
            __('Rekord rDNS zapisany.'));
    }

    public function rename(Request $request, ExternalServer $server): RedirectResponse
    {
        $this->authorize('operate', $server);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $this->manager->rename($server, $data['name'], $request->user());

        return back()->with('status', __('Nazwa zapisana.'));
    }

    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (\DomainException|ProviderException $e) {
            return back()->withErrors(['server' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['server' => __('Operacja nie powiodła się. Spróbuj ponownie za chwilę.')]);
        }

        return back()->with('status', $success);
    }

    /** @return list<string> */
    private function capabilities(ExternalServer $server): array
    {
        try {
            return $this->manager->driver($server)->capabilities();
        } catch (Throwable) {
            return [];
        }
    }

    /** Systemy do reinstalacji: te same co w produkcie, z którego zamówiono serwer. */
    private function images(ExternalServer $server): array
    {
        $product = $server->billingService?->product;
        if ($product !== null && $product->externalImages() !== []) {
            return $product->externalImages();
        }

        try {
            return $this->manager->catalog($server->account)['images'];
        } catch (Throwable) {
            return [];
        }
    }
}
