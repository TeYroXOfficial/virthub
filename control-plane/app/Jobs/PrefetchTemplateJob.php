<?php

namespace App\Jobs;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Models\TemplateDownload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Pobranie szablonu kontenera na jeden węzeł.
 *
 * Zleca pobranie agentowi, a potem wraca do kolejki co kilkanaście sekund i
 * dopytuje o wynik. Nie trzyma workera przez kilka minut ściągania obrazu —
 * między sprawdzeniami worker obsługuje inne zadania.
 */
class PrefetchTemplateJob implements ShouldQueue
{
    use Queueable;

    private const POLL_SECONDS = 5;

    public function __construct(public readonly int $downloadId) {}

    /** Duży obraz przez wolne łącze potrafi się ściągać długo, ale nie w nieskończoność. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(): void
    {
        $download = TemplateDownload::with(['template', 'hypervisor'])->find($this->downloadId);

        if ($download === null || ! $download->isInProgress()) {
            return;
        }

        $client = new AgentClient($download->hypervisor);

        try {
            if ($download->agent_job_id === null) {
                $jobId = $client->prefetchImage($download->template->image_file);

                $download->forceFill([
                    'agent_job_id' => $jobId,
                    'status' => TemplateDownload::STATUS_DOWNLOADING,
                ])->save();

                $this->release(self::POLL_SECONDS);

                return;
            }

            $state = $client->job($download->agent_job_id);
        } catch (AgentException $e) {
            // Węzeł chwilowo nieosiągalny — spróbujemy za minutę. Odmowa
            // (np. węzeł KVM dostał szablon kontenera) to błąd trwały.
            if ($e->isRetryable()) {
                $this->release(60);

                return;
            }

            self::finish($download, TemplateDownload::STATUS_FAILED, $e->getMessage());

            return;
        }

        if (! self::apply($download, $state)) {
            $this->release(self::POLL_SECONDS);
        }
    }

    /**
     * Stan zadania z węzła → pobranie w panelu (true = zakończone). Wołane też
     * przez stronę szablonów, która odpytuje węzły na żywo dla paska postępu.
     *
     * @param  array<string, mixed>  $state
     */
    public static function apply(TemplateDownload $download, array $state): bool
    {
        match ($state['status'] ?? null) {
            'done' => self::finish($download, TemplateDownload::STATUS_READY),
            'failed' => self::finish($download, TemplateDownload::STATUS_FAILED, $state['error'] ?? 'Węzeł nie podał przyczyny.'),
            // Odświeżony znacznik czasu mówi dystrybutorowi, że zlecenie żyje —
            // inaczej po dwóch godzinach uznałby je za zgubione i zlecił drugie.
            default => $download->forceFill([
                'progress' => $state['progress'] ?? $download->progress,
                'progress_detail' => $state['detail'] ?? $download->progress_detail,
            ])->touch(),
        };

        return $download->fresh()?->isInProgress() === false;
    }

    /** Wołane przez kolejkę, gdy minie retryUntil albo zadanie rzuci wyjątkiem. */
    public function failed(?Throwable $exception): void
    {
        $download = TemplateDownload::find($this->downloadId);

        if ($download?->isInProgress()) {
            self::finish(
                $download,
                TemplateDownload::STATUS_FAILED,
                $exception?->getMessage() ?: 'Pobieranie trwało zbyt długo.',
            );
        }
    }

    private static function finish(TemplateDownload $download, string $status, ?string $error = null): void
    {
        $download->forceFill([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
            'progress' => $status === TemplateDownload::STATUS_READY ? 100 : $download->progress,
            'progress_detail' => null,
        ])->save();
    }
}
