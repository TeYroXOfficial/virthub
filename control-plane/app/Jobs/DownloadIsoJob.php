<?php

namespace App\Jobs;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Models\IsoDownload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pobranie obrazu ISO na jeden węzeł — ten sam wzorzec co PrefetchTemplateJob:
 * zlecenie agentowi, potem dopytywanie o wynik co kilkanaście sekund bez
 * trzymania workera przez całe pobieranie.
 */
class DownloadIsoJob implements ShouldQueue
{
    use Queueable;

    private const POLL_SECONDS = 15;

    public function __construct(public readonly int $downloadId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function handle(): void
    {
        $download = IsoDownload::with(['iso', 'hypervisor'])->find($this->downloadId);

        if ($download === null || ! $download->isInProgress()) {
            return;
        }

        $client = new AgentClient($download->hypervisor);

        try {
            if ($download->agent_job_id === null) {
                $jobId = $client->downloadIso($download->iso->filename, $download->iso->url, $download->iso->sha256);
                $download->forceFill(['agent_job_id' => $jobId, 'status' => IsoDownload::STATUS_DOWNLOADING])->save();
                $this->release(self::POLL_SECONDS);

                return;
            }

            $state = $client->job($download->agent_job_id);
        } catch (AgentException $e) {
            if ($e->isRetryable()) {
                $this->release(60);

                return;
            }

            $this->finish($download, IsoDownload::STATUS_FAILED, $e->getMessage());

            return;
        }

        match ($state['status'] ?? null) {
            'done' => $this->succeed($download, $state['result'] ?? []),
            'failed' => $this->finish($download, IsoDownload::STATUS_FAILED, $state['error'] ?? 'Węzeł nie podał przyczyny.'),
            default => $this->touchAndWait($download),
        };
    }

    public function failed(?Throwable $exception): void
    {
        $download = IsoDownload::find($this->downloadId);

        if ($download?->isInProgress()) {
            $this->finish($download, IsoDownload::STATUS_FAILED, $exception?->getMessage() ?: 'Pobieranie trwało zbyt długo.');
        }
    }

    private function succeed(IsoDownload $download, array $result): void
    {
        $this->finish($download, IsoDownload::STATUS_READY);

        if (! empty($result['size_bytes']) && $download->iso->size_bytes === null) {
            $download->iso->forceFill(['size_bytes' => (int) $result['size_bytes']])->save();
        }
    }

    private function touchAndWait(IsoDownload $download): void
    {
        $download->touch();
        $this->release(self::POLL_SECONDS);
    }

    private function finish(IsoDownload $download, string $status, ?string $error = null): void
    {
        $download->forceFill(['status' => $status, 'error' => $error, 'finished_at' => now()])->save();
    }
}
