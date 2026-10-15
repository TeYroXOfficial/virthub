<?php

namespace App\Domain\Updates;

use App\Domain\Licensing\LicenseManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Wersje i aktualizacje panelu.
 *
 * Panel działa jako www-data i nie może sam podmienić swojego kodu ani
 * zrestartować usług. Zostawia plik-zlecenie w update_dir, a jednostka
 * systemd virthub-panel-update.path uruchamia jako root
 * infra/update-panel.sh (instalator z zapisanymi parametrami instalacji).
 * Ten sam wzorzec mają węzły — patrz node-agent/agent/updates.py.
 *
 * Z licencją wszystkie aktualizacje idą przez portal VirtHub: wersje semver
 * (plik VERSION), kanał licencji, paczka podpisana Ed25519. Panel przekazuje
 * usłudze tylko adres i podpis — usługa (root) sprawdza go kluczem zapisanym
 * lokalnie przy instalacji, więc przejęty panel nie podsunie obcego kodu.
 * Bez licencji zostaje dawny tryb: najnowszy commit z GitHuba.
 */
class Updates
{
    /** Po ilu sekundach nieodebrane zlecenie uznajemy za zawieszone. */
    public const STALL_AFTER = 90;

    public function __construct(private readonly LicenseManager $license) {}

    /** Wersja semver panelu (plik VERSION w katalogu instalacji). */
    public function version(): ?string
    {
        $file = base_path('../VERSION');
        $value = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return Semver::valid($value) ? $value : null;
    }

    /** Aktualizacje z portalu: licencja ustawiona i panel zna swoją wersję. */
    public function viaPortal(): bool
    {
        return $this->license->configured() && $this->license->key() !== null && $this->version() !== null;
    }

    /**
     * Dostępne wydania z portalu (cache 10 minut).
     *
     * @return array{ok: bool, error?: string, current?: string, channel?: string, update_available?: bool, latest?: ?array, releases?: list<array>, security?: bool}
     */
    public function check(bool $fresh = false): array
    {
        if (! $this->viaPortal()) {
            return ['ok' => false, 'error' => __('Panel nie ma licencji — aktualizacje z portalu wymagają klucza licencji (Administracja → System → Licencja i addony).')];
        }
        $key = 'updates:portal:'.$this->version();
        if ($fresh) {
            Cache::forget($key);
        }
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $result = $this->ask(['current' => $this->version()]);
        // Błędu nie trzymamy w cache — ponowne „Sprawdź teraz” ma od razu spróbować.
        if ($result['ok']) {
            Cache::put($key, $result, now()->addMinutes(10));
        }

        return $result;
    }

    /** Wynik z cache bez zapytania do portalu (pulpit nie czeka na sieć). */
    public function cachedCheck(): ?array
    {
        return $this->version() ? Cache::get('updates:portal:'.$this->version()) : null;
    }

    /**
     * Paczka konkretnej wersji — węzły doganiają wersję panelu.
     *
     * @return array{version: string, download_url: string, sha256: string, signature: string}
     *
     * @throws \RuntimeException
     */
    public function release(string $version): array
    {
        $result = $this->ask(['current' => $this->version(), 'target' => $version]);
        if (! $result['ok'] || empty($result['latest']['download_url'])) {
            throw new \RuntimeException($result['error'] ?? __('Portal nie udostępnił wydania :version.', ['version' => $version]));
        }

        return $result['latest'];
    }

    /** Pola wydania, które dostaje usługa aktualizacji (panelu albo węzła). */
    public static function releasePayload(array $release): array
    {
        return [
            'version' => (string) $release['version'],
            'url' => (string) $release['download_url'],
            'sha256' => (string) $release['sha256'],
            'signature' => (string) $release['signature'],
        ];
    }

    private function ask(array $data): array
    {
        try {
            $response = $this->license->request('POST', '/api/v1/updates/check', $data, 15);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'error' => __('Portal nie odpowiada: :error', ['error' => $e->getMessage()])];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'error' => (string) ($response->json('message') ?? __('Portal zwrócił błąd :status.', ['status' => $response->status()]))];
        }

        return ['ok' => true] + (array) $response->json();
    }

    /** Commit, z którego działa panel: z repozytorium git albo z pliku instalatora. */
    public function panelVersion(): ?string
    {
        $root = realpath(base_path('..')) ?: base_path('..');

        if (($sha = $this->gitHead($root.'/.git')) !== null) {
            return $sha;
        }

        $file = storage_path('app/virthub-version');

        return is_file($file) ? (trim((string) file_get_contents($file)) ?: null) : null;
    }

    /**
     * Najnowszy commit w repozytorium aktualizacji (cache 10 minut — limit
     * anonimowego API GitHuba to 60 zapytań na godzinę).
     *
     * @return array{sha: string, message: string, date: ?string, url: string}|null
     */
    public function latest(bool $fresh = false): ?array
    {
        $repo = (string) config('virthub.update_repo');
        $branch = (string) config('virthub.update_branch');
        $key = "updates:latest:{$repo}:{$branch}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(10), function () use ($repo, $branch) {
            try {
                $response = Http::timeout(8)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get("https://api.github.com/repos/{$repo}/commits/{$branch}");
            } catch (Throwable) {
                return null;
            }

            if (! $response->successful() || ! is_string($response->json('sha'))) {
                return null;
            }

            return [
                'sha' => $response->json('sha'),
                'message' => strtok((string) $response->json('commit.message'), "\n") ?: '',
                'date' => $response->json('commit.committer.date'),
                'url' => (string) $response->json('html_url'),
            ];
        });
    }

    /**
     * Najnowszy commit tylko z cache — bez zapytania do GitHuba (pulpit nie
     * może czekać na sieć). Null, gdy nikt jeszcze nie sprawdzał.
     *
     * @return array{sha: string, message: string, date: ?string, url: string}|null
     */
    public function cachedLatest(): ?array
    {
        return Cache::get('updates:latest:'.config('virthub.update_repo').':'.config('virthub.update_branch'));
    }

    public function panelUpdatesEnabled(): bool
    {
        return is_file((string) config('virthub.update_unit'))
            && is_writable((string) config('virthub.update_dir'));
    }

    /** @return array{state: string, started_at?: int, finished_at?: int, message?: string, log?: string} */
    public function panelStatus(): array
    {
        $dir = (string) config('virthub.update_dir');
        $status = ['state' => 'idle'];

        if (is_file("{$dir}/status.json")) {
            $status = json_decode((string) file_get_contents("{$dir}/status.json"), true) ?: $status;
        }

        if (is_file("{$dir}/request") && ($status['state'] ?? '') !== 'running') {
            // Usługa zdejmuje zlecenie w chwili startu. Jeśli leży dłużej,
            // systemd go nie odebrał — np. jednostka .path jest wyłączona.
            if (time() - (int) @filemtime("{$dir}/request") > self::STALL_AFTER) {
                $status['state'] = 'stalled';
                $status['message'] = __('Usługa aktualizacji nie odebrała zlecenia. Uruchom raz na serwerze panelu: ')
                    .__('sudo bash /opt/virthub/infra/update-panel.sh — naprawi usługę i zaktualizuje panel.');
            } else {
                $status['state'] = 'queued';
            }
        }

        if (is_file("{$dir}/last.log")) {
            $lines = file("{$dir}/last.log", FILE_IGNORE_NEW_LINES) ?: [];
            $status['log'] = implode("\n", array_slice($lines, -40));
        }

        return $status;
    }

    /**
     * Zlecenie aktualizacji. Z portalem: najnowsze wydanie z kanału licencji
     * (release.json obok zlecenia); bez — dawny tryb z repozytorium.
     *
     * @return ?string wersja docelowa (tryb portalu)
     *
     * @throws \RuntimeException
     */
    public function requestPanelUpdate(): ?string
    {
        if (! $this->panelUpdatesEnabled()) {
            throw new \RuntimeException(
                __('Zdalna aktualizacja panelu nie jest włączona. Uruchom raz instalator panelu ')
                .__('w najnowszej wersji — założy usługę virthub-panel-update.')
            );
        }

        if (in_array($this->panelStatus()['state'], ['queued', 'running'], true)) {
            throw new \RuntimeException(__('Aktualizacja panelu już trwa.'));
        }

        $dir = (string) config('virthub.update_dir');
        $target = null;
        if ($this->viaPortal()) {
            $check = $this->check(true);
            if (! $check['ok']) {
                throw new \RuntimeException($check['error']);
            }
            if (empty($check['update_available']) || empty($check['latest']['download_url'])) {
                throw new \RuntimeException(__('Panel ma najnowszą wersję :version.', ['version' => $this->version()]));
            }
            $target = (string) $check['latest']['version'];
            file_put_contents("{$dir}/release.json", json_encode(self::releasePayload($check['latest']), JSON_UNESCAPED_SLASHES));
        } else {
            @unlink("{$dir}/release.json");
        }

        file_put_contents("{$dir}/request", json_encode(['requested_at' => time(), 'target' => $target]));

        return $target;
    }

    /** Commit skrócony do 7 znaków; wersja semver bez zmian. */
    public static function short(?string $sha): string
    {
        if ($sha === null || $sha === '') {
            return '—';
        }

        return preg_match('/^[0-9a-f]{40}$/', $sha) ? substr($sha, 0, 7) : $sha;
    }

    /** Odczyt HEAD bez uruchamiania gita — www-data nie musi go mieć ani ufać katalogowi. */
    private function gitHead(string $gitDir): ?string
    {
        $head = @file_get_contents("{$gitDir}/HEAD");

        if ($head === false) {
            return null;
        }

        $head = trim($head);

        if (! str_starts_with($head, 'ref: ')) {
            return preg_match('/^[0-9a-f]{40}$/', $head) ? $head : null;
        }

        $ref = substr($head, 5);
        $loose = @file_get_contents("{$gitDir}/{$ref}");

        if ($loose !== false && preg_match('/^[0-9a-f]{40}/', trim($loose), $m)) {
            return $m[0];
        }

        foreach (@file("{$gitDir}/packed-refs", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_ends_with($line, ' '.$ref) && preg_match('/^[0-9a-f]{40}/', $line, $m)) {
                return $m[0];
            }
        }

        return null;
    }
}
