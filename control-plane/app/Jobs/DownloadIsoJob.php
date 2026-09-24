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

    private const POLL_SECONDS = 5;

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

            self::finish($download, IsoDownload::STATUS_FAILED, $e->getMessage());

            return;
        }

        if (! self::apply($download, $state)) {
            $this->release(self::POLL_SECONDS);
        }
    }

    /**
     * Stan zadania z węzła → pobranie w panelu. Zwraca true, gdy pobieranie
     * się zakończyło. Wołane też przez stronę biblioteki, która odpytuje węzły
     * na żywo, żeby pasek postępu nie czekał na kolejny obieg kolejki.
     *
     * @param  array<string, mixed>  $state
     */
    public static function apply(IsoDownload $download, array $state): bool
    {
        match ($state['status'] ?? null) {
            'done' => self::succeed($download, $state['result'] ?? []),
            'failed' => self::finish($download, IsoDownload::STATUS_FAILED, $state['error'] ?? 'Węzeł nie podał przyczyny.'),
            // Odświeżony znacznik czasu mówi, że zlecenie żyje.
            default => $download->forceFill([
                'progress' => $state['progress'] ?? $download->progress,
                'progress_detail' => $state['detail'] ?? $download->progress_detail,
            ])->touch(),
        };

        return $download->fresh()?->isInProgress() === false;
    }

    public function failed(?Throwable $exception): void
    {
        $download = IsoDownload::find($this->downloadId);

        if ($download?->isInProgress()) {
            self::finish($download, IsoDownload::STATUS_FAILED, $exception?->getMessage() ?: 'Pobieranie trwało zbyt długo.');
        }
    }

    private static function succeed(IsoDownload $download, array $result): void
    {
        self::finish($download, IsoDownload::STATUS_READY);

        if (! empty($result['size_bytes']) && $download->iso->size_bytes === null) {
            $download->iso->forceFill(['size_bytes' => (int) $result['size_bytes']])->save();
        }
    }

    private static function finish(IsoDownload $download, string $status, ?string $error = null): void
    {
        $download->forceFill([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
            'progress' => $status === IsoDownload::STATUS_READY ? 100 : $download->progress,
            'progress_detail' => null,
        ])->save();
    }
}
