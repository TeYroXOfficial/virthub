<?php

namespace App\Http\Controllers\Web;

use App\Domain\Agent\AgentException;
use App\Domain\Apps\AppProvisioner;
use App\Http\Controllers\Controller;
use App\Models\AppServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Menedżer plików aplikacji. Wszystkie operacje wykonuje agent węzła
 * (bezpiecznie wobec dowiązań symbolicznych) — panel tylko pośredniczy.
 */
class AppFilesController extends Controller
{
    /** Kawałek pliku w jednym żądaniu do węzła (~5,3 MB po base64). */
    private const UPLOAD_CHUNK = 4 * 1024 * 1024;

    private const EDITABLE_LIMIT = 2 * 1024 * 1024;

    public function __construct(private readonly AppProvisioner $apps) {}

    public function index(Request $request, AppServer $app): View|RedirectResponse
    {
        $this->authorize('operate', $app);
        $path = $this->path($request->query('path', ''));
        $app->load(['egg', 'plan', 'allocations', 'hypervisor', 'user']);

        $entries = [];
        $error = null;
        try {
            $this->apps->assertUsable($app);
            $entries = $this->apps->client($app)->appFiles($app->uuid, 'list', ['path' => $path])['entries'] ?? [];
        } catch (\DomainException|AgentException $e) {
            $error = $e->getMessage();
        }

        return view('panel.apps.files', [
            'app' => $app,
            'tab' => 'files',
            'lastJob' => $app->jobs()->first(),
            'path' => $path,
            'entries' => $entries,
            'error' => $error,
        ]);
    }

    public function edit(Request $request, AppServer $app): View|RedirectResponse
    {
        $this->authorize('operate', $app);
        $path = $this->path($request->query('path', ''));
        $app->load(['egg', 'plan', 'allocations', 'hypervisor', 'user']);

        $content = '';
        $binary = false;
        if (! $request->boolean('new')) {
            try {
                $this->apps->assertUsable($app);
                $data = base64_decode($this->apps->client($app)->appFiles($app->uuid, 'read', ['path' => $path])['content_base64'] ?? '');
            } catch (\DomainException|AgentException $e) {
                return redirect()->route('panel.apps.files', [$app, 'path' => $this->parent($path)])->withErrors(['files' => $e->getMessage()]);
            }
            $binary = strlen($data) > self::EDITABLE_LIMIT || str_contains($data, "\0") || ! mb_check_encoding($data, 'UTF-8');
            $content = $binary ? '' : $data;
        }

        return view('panel.apps.file-edit', [
            'app' => $app,
            'tab' => 'files',
            'lastJob' => null,
            'path' => $path,
            'content' => $content,
            'binary' => $binary,
            'isNew' => $request->boolean('new'),
        ]);
    }

    public function save(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $validated = $request->validate([
            'path' => ['required', 'string', 'max:1024'],
            'content' => ['nullable', 'string', 'max:'.self::EDITABLE_LIMIT],
        ]);
        $path = $this->path($validated['path']);

        return $this->run($app, 'write', ['path' => $path, 'content_base64' => base64_encode(str_replace("\r\n", "\n", $validated['content'] ?? ''))],
            __('Zapisano :path.', ['path' => $path]), route('panel.apps.files.edit', [$app, 'path' => $path]));
    }

    public function download(Request $request, AppServer $app): Response|RedirectResponse
    {
        $this->authorize('operate', $app);
        $path = $this->path($request->query('path', ''));

        try {
            $this->apps->assertUsable($app);
            $data = base64_decode($this->apps->client($app)->appFiles($app->uuid, 'read', ['path' => $path])['content_base64'] ?? '');
        } catch (\DomainException|AgentException $e) {
            return back()->withErrors(['files' => $e->getMessage()]);
        }

        return response($data, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addcslashes(basename($path) ?: 'plik', '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function upload(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $request->validate([
            'path' => ['nullable', 'string', 'max:1024'],
            'files' => ['required', 'array', 'max:20'],
            'files.*' => ['file', 'max:51200'],
        ]);
        $dir = $this->path($request->input('path', ''));

        try {
            $this->apps->assertUsable($app);
            $client = $this->apps->client($app);
            foreach ($request->file('files') as $file) {
                $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $this->sendInChunks($client, $app, ltrim($dir.'/'.$name, '/'), $file->getRealPath());
            }
        } catch (\DomainException|AgentException $e) {
            return $this->back($app, $dir)->withErrors(['files' => $e->getMessage()]);
        }

        return $this->back($app, $dir)->with('status', __('Wgrano pliki: :count.', ['count' => count($request->file('files'))]));
    }

    /**
     * Plik na węzeł kawałkami po 4 MB: każde żądanie mieści się w limicie
     * nginx węzła, a panel nie trzyma w pamięci całego pliku w base64.
     */
    private function sendInChunks(\App\Domain\Agent\AgentClient $client, AppServer $app, string $path, string $source): void
    {
        $handle = fopen($source, 'rb');
        if ($handle === false) {
            throw new \DomainException(__('Nie udało się odczytać wgranego pliku.'));
        }
        try {
            $first = true;
            do {
                $chunk = (string) fread($handle, self::UPLOAD_CHUNK);
                if ($chunk === '' && ! $first) {
                    break;
                }
                $client->appFiles($app->uuid, 'write', array_filter([
                    'path' => $path,
                    'content_base64' => base64_encode($chunk),
                    'append' => $first ? null : true,
                ], fn ($v) => $v !== null));
                $first = false;
            } while (! feof($handle));
        } finally {
            fclose($handle);
        }
    }

    public function mkdir(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $validated = $request->validate(['path' => ['nullable', 'string', 'max:1024'], 'name' => ['required', 'string', 'max:255', 'regex:/^[^\/\\\\\x00]+$/']]);
        $dir = $this->path($validated['path'] ?? '');

        return $this->run($app, 'mkdir', ['path' => ltrim($dir.'/'.$validated['name'], '/')],
            __('Utworzono katalog :name.', ['name' => $validated['name']]), $this->filesUrl($app, $dir));
    }

    public function delete(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $validated = $request->validate(['path' => ['nullable', 'string', 'max:1024'], 'names' => ['required', 'array', 'max:500'], 'names.*' => ['string', 'max:255']]);
        $dir = $this->path($validated['path'] ?? '');
        $paths = array_map(fn ($n) => ltrim($dir.'/'.basename(str_replace('\\', '/', $n)), '/'), $validated['names']);

        return $this->run($app, 'delete', ['paths' => $paths],
            __('Usunięto: :count.', ['count' => count($paths)]), $this->filesUrl($app, $dir));
    }

    public function rename(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $validated = $request->validate(['path' => ['nullable', 'string', 'max:1024'], 'from' => ['required', 'string', 'max:255'], 'to' => ['required', 'string', 'max:1024']]);
        $dir = $this->path($validated['path'] ?? '');
        $target = str_starts_with($validated['to'], '/') ? $this->path($validated['to']) : ltrim($dir.'/'.$validated['to'], '/');

        return $this->run($app, 'rename', ['source' => ltrim($dir.'/'.basename($validated['from']), '/'), 'target' => $target],
            __('Zmieniono nazwę na :to.', ['to' => $validated['to']]), $this->filesUrl($app, $dir));
    }

    public function decompress(Request $request, AppServer $app): RedirectResponse
    {
        $this->authorize('operate', $app);
        $validated = $request->validate(['path' => ['nullable', 'string', 'max:1024'], 'name' => ['required', 'string', 'max:255']]);
        $dir = $this->path($validated['path'] ?? '');

        return $this->run($app, 'decompress', ['path' => ltrim($dir.'/'.basename($validated['name']), '/')],
            __('Rozpakowano :name.', ['name' => $validated['name']]), $this->filesUrl($app, $dir));
    }

    // --- pomocnicze -----------------------------------------------------------------

    private function run(AppServer $app, string $operation, array $body, string $message, string $redirect): RedirectResponse
    {
        try {
            $this->apps->assertUsable($app);
            $this->apps->client($app)->appFiles($app->uuid, $operation, $body);
        } catch (\DomainException|AgentException $e) {
            return redirect()->to($redirect)->withErrors(['files' => $e->getMessage()]);
        }

        return redirect()->to($redirect)->with('status', $message);
    }

    private function path(mixed $path): string
    {
        $path = is_string($path) ? $path : '';
        $parts = array_filter(explode('/', str_replace('\\', '/', $path)), fn ($p) => $p !== '' && $p !== '.');

        return implode('/', $parts);
    }

    private function parent(string $path): string
    {
        return str_contains($path, '/') ? substr($path, 0, strrpos($path, '/')) : '';
    }

    private function filesUrl(AppServer $app, string $dir): string
    {
        return route('panel.apps.files', [$app, 'path' => $dir]);
    }

    private function back(AppServer $app, string $dir): RedirectResponse
    {
        return redirect()->to($this->filesUrl($app, $dir));
    }
}
