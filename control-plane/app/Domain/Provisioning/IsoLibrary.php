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
use App\Models\Server;
use App\Models\Setting;
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
            throw ValidationException::withMessages(['name' => __('Obraz o takiej nazwie już jest w bibliotece.')]);
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

    /** Domyślne limity własnych obrazów klienta (Administracja → Obrazy ISO). */
    public const CUSTOM_LIMIT = 1;

    public const CUSTOM_MAX_GB = 10;

    public static function customLimit(): int
    {
        return (int) Setting::get('iso.custom_limit', (string) self::CUSTOM_LIMIT);
    }

    public static function customMaxGb(): int
    {
        return max(1, (int) Setting::get('iso.custom_max_gb', (string) self::CUSTOM_MAX_GB));
    }

    /**
     * Własny obraz klienta z adresu URL — pobierany tylko na węzeł maszyny, z
     * ochroną sieci węzła (agent odrzuca adresy prywatne) i limitem rozmiaru.
     */
    public function addCustom(Server $server, string $name, string $url, ?User $actor = null): IsoImage
    {
        if ($server->isContainer() || $server->hypervisor_id === null) {
            throw ValidationException::withMessages(['custom_url' => __('Własne obrazy ISO są dostępne tylko dla maszyn KVM.')]);
        }
        $limit = self::customLimit();
        if ($limit <= 0) {
            throw ValidationException::withMessages(['custom_url' => __('Własne obrazy ISO są wyłączone przez administratora.')]);
        }
        if (IsoImage::query()->where('server_id', $server->id)->count() >= $limit) {
            throw ValidationException::withMessages(['custom_url' => trans_choice('Limit: :count własny obraz na maszynę — usuń poprzedni.|Limit: :count własne obrazy na maszynę — usuń poprzedni.|Limit: :count własnych obrazów na maszynę — usuń poprzedni.', $limit)]);
        }

        $iso = IsoImage::create([
            'name' => $name,
            'filename' => 'vm'.$server->id.'-'.Str::lower(Str::random(8)).'.iso',
            'url' => $url,
            'is_public' => false,
            'server_id' => $server->id,
        ]);
        $download = IsoDownload::create(['iso_image_id' => $iso->id, 'hypervisor_id' => $server->hypervisor_id, 'status' => IsoDownload::STATUS_QUEUED]);
        AuditLog::record('iso.custom_added', $server, ['name' => $name, 'url' => $url], $actor);
        DownloadIsoJob::dispatch($download->id);

        return $iso;
    }

    /** Zleca pobranie na węzły, które jeszcze go nie mają. */
    public function distribute(IsoImage $iso, bool $retryFailed = false): int
    {
        $queued = 0;
        $nodes = $iso->isCustom()
            ? Hypervisor::query()->whereKey($iso->owner?->hypervisor_id)->get()
            : $this->kvmNodes();

        foreach ($nodes as $node) {
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
                'iso' => __('Obraz :name jest zamontowany w maszynach — wysuń go najpierw.', ['name' => $iso->name]),
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

    /** Sprzątanie po usuniętej maszynie: jej własne obrazy znikają z węzła. */
    public function deleteCustomFor(Server $server): void
    {
        foreach (IsoImage::query()->where('server_id', $server->id)->with('downloads.hypervisor')->get() as $iso) {
            foreach ($iso->downloads as $download) {
                try {
                    (new AgentClient($download->hypervisor))->deleteIso($iso->filename);
                } catch (AgentException) {
                    // Węzeł niedostępny — plik zostanie jako sierota.
                }
            }
            $iso->delete();
        }
    }

    /** @return Collection<int, IsoImage> obrazy, które można zamontować w maszynie na danym węźle */
    public function availableFor(?int $hypervisorId, bool $includeHidden, ?Server $server = null): Collection
    {
        return IsoImage::query()
            // Własne obrazy klientów: tylko w maszynie, która je dodała.
            ->where(fn ($q) => $q->whereNull('server_id')->when($server, fn ($w) => $w->orWhere('server_id', $server->id)))
            ->when(! $includeHidden, fn ($q) => $q->where(fn ($w) => $w->where('is_public', true)->when($server, fn ($v) => $v->orWhere('server_id', $server->id))))
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
