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

        match ($state['status'] ?? 'failed') {
            'done' => $this->succeed($job),
            'failed' => $this->fail($job, $state['error'] ?? __('Węzeł nie podał przyczyny.')),
            default => null,
        };
    }

    public function succeed(AppJob $job): void
    {
        $job->forceFill(['status' => AppJob::STATUS_DONE, 'finished_at' => now()])->save();
        $job->server?->forceFill([
            'status' => AppServer::STATUS_READY,
            'status_message' => null,
            'installed_at' => now(),
        ])->save();
    }

    public function fail(AppJob $job, string $error): void
    {
        $job->forceFill(['status' => AppJob::STATUS_FAILED, 'error' => $error, 'finished_at' => now()])->save();
        $job->server?->forceFill([
            'status' => AppServer::STATUS_INSTALL_FAILED,
            'status_message' => mb_substr($error, 0, 2000),
        ])->save();
    }
}
