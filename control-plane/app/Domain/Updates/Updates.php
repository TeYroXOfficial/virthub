<?php

namespace App\Domain\Updates;

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
 */
class Updates
{
    /** Po ilu sekundach nieodebrane zlecenie uznajemy za zawieszone. */
    public const STALL_AFTER = 90;

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
                $status['message'] = 'Usługa aktualizacji nie odebrała zlecenia. Uruchom raz na serwerze panelu: '
                    .'sudo bash /opt/virthub/infra/update-panel.sh — naprawi usługę i zaktualizuje panel.';
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

    /** @throws \RuntimeException */
    public function requestPanelUpdate(): void
    {
        if (! $this->panelUpdatesEnabled()) {
            throw new \RuntimeException(
                'Zdalna aktualizacja panelu nie jest włączona. Uruchom raz instalator panelu '
                .'w najnowszej wersji — założy usługę virthub-panel-update.'
            );
        }

        if (in_array($this->panelStatus()['state'], ['queued', 'running'], true)) {
            throw new \RuntimeException('Aktualizacja panelu już trwa.');
        }

        file_put_contents(
            config('virthub.update_dir').'/request',
            json_encode(['requested_at' => time()]),
        );
    }

    public static function short(?string $sha): string
    {
        return $sha ? substr($sha, 0, 7) : '—';
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
