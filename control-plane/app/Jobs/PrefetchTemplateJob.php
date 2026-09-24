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

    private const POLL_SECONDS = 15;

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

            $this->finish($download, TemplateDownload::STATUS_FAILED, $e->getMessage());

            return;
        }

        match ($state['status'] ?? null) {
            'done' => $this->finish($download, TemplateDownload::STATUS_READY),
            'failed' => $this->finish(
                $download,
                TemplateDownload::STATUS_FAILED,
                $state['error'] ?? 'Węzeł nie podał przyczyny.',
            ),
            default => $this->touchAndWait($download),
        };
    }

    /** Wołane przez kolejkę, gdy minie retryUntil albo zadanie rzuci wyjątkiem. */
    public function failed(?Throwable $exception): void
    {
        $download = TemplateDownload::find($this->downloadId);

        if ($download?->isInProgress()) {
            $this->finish(
                $download,
                TemplateDownload::STATUS_FAILED,
                $exception?->getMessage() ?: 'Pobieranie trwało zbyt długo.',
            );
        }
    }

    private function touchAndWait(TemplateDownload $download): void
    {
        // Odświeżony znacznik czasu mówi dystrybutorowi, że zlecenie żyje —
        // inaczej po dwóch godzinach uznałby je za zgubione i zlecił drugie.
        $download->touch();
        $this->release(self::POLL_SECONDS);
    }

    private function finish(TemplateDownload $download, string $status, ?string $error = null): void
    {
        $download->forceFill([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
        ])->save();
    }
}
