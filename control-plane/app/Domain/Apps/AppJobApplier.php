<?php

namespace App\Domain\Apps;

use App\Models\AppJob;
use App\Models\AppServer;

/** Wynik zadania aplikacji z węzła (callback albo uzgadnianie) → stan w panelu. */
class AppJobApplier
{
    /** @param  array{status: string, result?: array|null, error?: string|null}  $state */
    public function apply(AppJob $job, array $state): void
    {
        if ($job->isFinished()) {
            return;
        }

        if (in_array($job->action, \App\Domain\Apps\Content\ContentManager::ACTIONS, true)) {
            $status = $state['status'] ?? 'failed';
            if (in_array($status, ['done', 'failed'], true)) {
                app(\App\Domain\Apps\Content\ContentManager::class)->applyResult($job, $status === 'done', $state['error'] ?? __('Węzeł nie podał przyczyny.'));
            }

            return;
        }

        match ($state['status'] ?? 'failed') {
            'done' => $this->succeed($job),
            'failed' => $this->fail($job, $state['error'] ?? __('Węzeł nie podał przyczyny.')),
            default => null,
        };
    }

    public function succeed(AppJob $job): void
    {
        $job->forceFill(['status' => AppJob::STATUS_DONE, 'finished_at' => now()])->save();
        app(PterodactylMigrator::class)->cleanup($job);
        $wipe = $job->payload['wipe'] ?? [];
        if ($job->action === 'reinstall' && $job->server) {
            if (array_intersect($wipe, ['*', 'plugins', 'mods'])) {
                $job->server->addons()->delete(); // pliki usunięte — wpisy też
            }
            // Skrypt mógł pobrać inną wersję gry, a czyszczenie całości usuwa loader.
            $minecraft = in_array('*', $wipe, true) ? null : array_diff_key($job->server->minecraft ?? [], ['mc' => true]);
            $job->server->forceFill(['minecraft' => $minecraft ?: null])->save();
        }
        $firstInstall = $job->server !== null && $job->server->installed_at === null;
        $job->server?->forceFill([
            'status' => AppServer::STATUS_READY,
            'status_message' => null,
            'installed_at' => now(),
        ])->save();

        if ($job->server && ($job->action === 'reinstall' || ($job->action === 'install' && $firstInstall))) {
            app(\App\Domain\Mail\Notify::class)->app($job->server, $job->action === 'reinstall' ? 'app.reinstalled' : 'app.created');
        }
    }

    public function fail(AppJob $job, string $error): void
    {
        $job->forceFill(['status' => AppJob::STATUS_FAILED, 'error' => $error, 'finished_at' => now()])->save();
        app(PterodactylMigrator::class)->cleanup($job);
        $job->server?->forceFill([
            'status' => AppServer::STATUS_INSTALL_FAILED,
            'status_message' => mb_substr($error, 0, 2000),
        ])->save();
    }
}
