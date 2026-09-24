<?php

namespace App\Domain\Provisioning;

use App\Domain\Agent\AgentClient;
use App\Domain\Agent\AgentException;
use App\Enums\Virtualization;
use App\Jobs\DownloadIsoJob;
use App\Models\AuditLog;
use App\Models\Hypervisor;
use App\Models\IsoDownload;
use App\Models\IsoImage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Biblioteka obrazów ISO: dodanie przez URL, pobranie na wszystkie węzły KVM,
 * ponowienie nieudanych, usunięcie. Kontenery nie mają napędu CD — węzły LXC
 * są pomijane.
 */
class IsoLibrary
{
    /** @param  array{name: string, url: string, sha256?: ?string, is_public?: bool}  $data */
    public function add(array $data, ?User $actor = null): IsoImage
    {
        $filename = Str::slug($data['name']).'.iso';

        if (IsoImage::where('filename', $filename)->exists()) {
            throw ValidationException::withMessages(['name' => 'Obraz o takiej nazwie już jest w bibliotece.']);
        }

        $iso = IsoImage::create([
            'name' => $data['name'],
            'filename' => $filename,
            'url' => $data['url'],
            'sha256' => ! empty($data['sha256']) ? Str::lower($data['sha256']) : null,
            'is_public' => (bool) ($data['is_public'] ?? true),
        ]);

        AuditLog::record('iso.added', $iso, ['url' => $iso->url], $actor);
        $this->distribute($iso);

        return $iso;
    }

    /** Zleca pobranie na węzły, które jeszcze go nie mają. */
    public function distribute(IsoImage $iso, bool $retryFailed = false): int
    {
        $queued = 0;

        foreach ($this->kvmNodes() as $node) {
            $download = IsoDownload::firstOrNew(['iso_image_id' => $iso->id, 'hypervisor_id' => $node->id]);

            if ($download->exists && ($download->status === IsoDownload::STATUS_READY
                || $download->isInProgress()
                || ($download->status === IsoDownload::STATUS_FAILED && ! $retryFailed))) {
                continue;
            }

            $download->forceFill([
                'status' => IsoDownload::STATUS_QUEUED,
                'agent_job_id' => null,
                'error' => null,
                'finished_at' => null,
                'progress' => null,
                'progress_detail' => null,
            ])->save();

            DownloadIsoJob::dispatch($download->id);
            $queued++;
        }

        return $queued;
    }

    public function delete(IsoImage $iso, ?User $actor = null): void
    {
        if ($iso->servers()->exists()) {
            throw ValidationException::withMessages([
                'iso' => "Obraz {$iso->name} jest zamontowany w maszynach — wysuń go najpierw.",
            ]);
        }

        foreach ($iso->downloads()->with('hypervisor')->get() as $download) {
            try {
                (new AgentClient($download->hypervisor))->deleteIso($iso->filename);
            } catch (AgentException) {
                // Węzeł niedostępny — plik zostanie jako sierota, bez szkody dla maszyn.
            }
        }

        AuditLog::record('iso.deleted', $iso, ['name' => $iso->name], $actor);
        $iso->delete();
    }

    /** @return Collection<int, IsoImage> obrazy, które można zamontować w maszynie na danym węźle */
    public function availableFor(?int $hypervisorId, bool $includeHidden): Collection
    {
        return IsoImage::query()
            ->when(! $includeHidden, fn ($q) => $q->where('is_public', true))
            ->whereHas('downloads', fn ($q) => $q
                ->where('hypervisor_id', $hypervisorId)
                ->where('status', IsoDownload::STATUS_READY))
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Hypervisor> */
    private function kvmNodes(): Collection
    {
        return Hypervisor::query()
            ->where('virtualization', Virtualization::Kvm->value)
            ->whereNotNull('enrolled_at')
            ->get();
    }
}
