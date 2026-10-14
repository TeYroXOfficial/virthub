<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Magazyn dysków maszyn: katalog na węzłach (qcow2, cienki albo gruby) albo
 * pula Ceph RBD (współdzielona). Klucz Cepha szyfrowany w bazie.
 *
 * @property array<string, mixed> $config
 */
class Datastore extends Model
{
    public const TYPE_DIRECTORY = 'directory';

    public const TYPE_CEPH = 'ceph';

    public const TYPES = [self::TYPE_DIRECTORY, self::TYPE_CEPH];

    protected $fillable = ['name', 'type', 'thin', 'config', 'is_active'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'thin' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsToMany<Hypervisor, $this> */
    public function hypervisors(): BelongsToMany
    {
        return $this->belongsToMany(Hypervisor::class)->withPivot(['total_gb', 'free_gb', 'ok', 'error', 'checked_at']);
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** @return HasMany<VpsPackage, $this> */
    public function packages(): HasMany
    {
        return $this->hasMany(VpsPackage::class);
    }

    public function isCeph(): bool
    {
        return $this->type === self::TYPE_CEPH;
    }

    public function typeLabel(): string
    {
        return $this->isCeph() ? 'Ceph RBD' : __('Katalog (qcow2)');
    }

    public function describe(): string
    {
        $c = (array) $this->config;

        return $this->isCeph()
            ? ($c['user'] ?? 'admin').'@'.implode(',', (array) ($c['monitors'] ?? [])).' / '.($c['pool'] ?? '')
            : (string) ($c['path'] ?? '');
    }

    /** Opis magazynu dla agenta (pole `storage` zlecenia utworzenia maszyny). */
    public function toAgentPayload(): array
    {
        $c = (array) $this->config;
        $spec = ['id' => $this->id, 'type' => $this->type, 'thin' => $this->isCeph() || $this->thin];

        return $this->isCeph()
            ? $spec + ['pool' => $c['pool'] ?? '', 'monitors' => array_values((array) ($c['monitors'] ?? [])), 'user' => $c['user'] ?? 'admin', 'key' => $c['key'] ?? '']
            : $spec + ['path' => $c['path'] ?? ''];
    }

    /** Suma pojemności z ostatnich odczytów (Ceph: jeden klaster — bierzemy najświeższy odczyt). */
    public function capacity(): array
    {
        $rows = $this->hypervisors->filter(fn (Hypervisor $h) => $h->pivot->ok && $h->pivot->total_gb !== null);
        if ($rows->isEmpty()) {
            return ['total_gb' => null, 'free_gb' => null, 'checked_at' => null];
        }
        if ($this->isCeph()) {
            $latest = $rows->sortByDesc(fn (Hypervisor $h) => (string) $h->pivot->checked_at)->first();

            return ['total_gb' => (int) $latest->pivot->total_gb, 'free_gb' => (int) $latest->pivot->free_gb, 'checked_at' => Carbon::parse($latest->pivot->checked_at)];
        }

        return [
            'total_gb' => (int) $rows->sum(fn (Hypervisor $h) => $h->pivot->total_gb),
            'free_gb' => (int) $rows->sum(fn (Hypervisor $h) => $h->pivot->free_gb),
            'checked_at' => Carbon::parse($rows->max(fn (Hypervisor $h) => (string) $h->pivot->checked_at)),
        ];
    }
}
