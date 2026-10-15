<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Magazyn dysków maszyn: katalog na węzłach (qcow2, cienki albo gruby), zasób
 * montowany przez agenta (NFS, SMB/CIFS, CephFS, iSCSI — też pliki qcow2) albo
 * dyski blokowe (Ceph RBD, StorPool, Lightbits). Sekrety szyfrowane w bazie.
 *
 * @property array<string, mixed> $config
 */
class Datastore extends Model
{
    public const TYPE_DIRECTORY = 'directory';

    public const TYPE_CEPH = 'ceph';

    public const TYPE_NFS = 'nfs';

    public const TYPE_CIFS = 'cifs';

    public const TYPE_CEPHFS = 'cephfs';

    public const TYPE_ISCSI = 'iscsi';

    public const TYPE_STORPOOL = 'storpool';

    public const TYPE_LIGHTBITS = 'lightbits';

    public const TYPES = [self::TYPE_DIRECTORY, self::TYPE_NFS, self::TYPE_CIFS, self::TYPE_CEPHFS, self::TYPE_ISCSI,
        self::TYPE_CEPH, self::TYPE_STORPOOL, self::TYPE_LIGHTBITS];

    /** Zasoby, które agent montuje sam; dyski to na nich pliki qcow2. */
    public const MOUNTED = [self::TYPE_NFS, self::TYPE_CIFS, self::TYPE_CEPHFS, self::TYPE_ISCSI];

    /** Dyski blokowe w systemie zewnętrznym. */
    public const BLOCK = [self::TYPE_CEPH, self::TYPE_STORPOOL, self::TYPE_LIGHTBITS];

    /** Sterowniki bez weryfikacji na prawdziwym klastrze. */
    public const EXPERIMENTAL = [self::TYPE_STORPOOL, self::TYPE_LIGHTBITS];

    /** Pola konfiguracji z sekretami (formularz: puste = bez zmiany). */
    public const SECRETS = ['key', 'password', 'jwt'];

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

    /** Dysk to plik qcow2 na węźle — Elevate, kopie przyrostowe, migracja z kopiowaniem dysku, DR. */
    public function isFileBased(): bool
    {
        return ! in_array($this->type, self::BLOCK, true);
    }

    /** Ten sam zasób widzą wszystkie przypięte węzły (pojemność liczona raz). */
    public function isShared(): bool
    {
        return $this->type !== self::TYPE_DIRECTORY;
    }

    public function isExperimental(): bool
    {
        return in_array($this->type, self::EXPERIMENTAL, true);
    }

    public static function labelFor(string $type): string
    {
        return match ($type) {
            self::TYPE_CEPH => 'Ceph RBD',
            self::TYPE_NFS => 'NFS',
            self::TYPE_CIFS => 'SMB / CIFS',
            self::TYPE_CEPHFS => 'CephFS',
            self::TYPE_ISCSI => 'iSCSI',
            self::TYPE_STORPOOL => 'StorPool',
            self::TYPE_LIGHTBITS => 'Lightbits NVMe/TCP',
            default => __('Katalog (qcow2)'),
        };
    }

    public function typeLabel(): string
    {
        return self::labelFor($this->type);
    }

    public function describe(): string
    {
        $c = (array) $this->config;

        return match ($this->type) {
            self::TYPE_CEPH => ($c['user'] ?? 'admin').'@'.implode(',', (array) ($c['monitors'] ?? [])).' / '.($c['pool'] ?? ''),
            self::TYPE_CEPHFS => ($c['user'] ?? 'admin').'@'.implode(',', (array) ($c['monitors'] ?? [])).':'.($c['export'] ?? '/'),
            self::TYPE_NFS => ($c['server'] ?? '').':'.($c['export'] ?? ''),
            self::TYPE_CIFS => '//'.($c['server'] ?? '').'/'.ltrim((string) ($c['export'] ?? ''), '/'),
            self::TYPE_ISCSI => ($c['portal'] ?? '').' '.($c['iqn'] ?? '').' LUN '.($c['lun'] ?? 0),
            self::TYPE_STORPOOL => __('szablon :name', ['name' => $c['template'] ?? '']),
            self::TYPE_LIGHTBITS => ($c['nqn'] ?? '').' / '.($c['project'] ?? 'default'),
            default => (string) ($c['path'] ?? ''),
        };
    }

    /** Opis magazynu dla agenta (pole `storage` zlecenia utworzenia maszyny). */
    public function toAgentPayload(): array
    {
        $c = (array) $this->config;
        $spec = ['id' => $this->id, 'type' => $this->type, 'thin' => ! $this->isFileBased() || $this->thin];
        $pick = fn (array $keys) => array_filter(array_intersect_key($c, array_flip($keys)), fn ($v) => $v !== null && $v !== '');

        return $spec + match ($this->type) {
            self::TYPE_CEPH => ['pool' => $c['pool'] ?? '', 'monitors' => array_values((array) ($c['monitors'] ?? [])), 'user' => $c['user'] ?? 'admin', 'key' => $c['key'] ?? ''],
            self::TYPE_CEPHFS => ['monitors' => array_values((array) ($c['monitors'] ?? [])), 'user' => $c['user'] ?? 'admin', 'key' => $c['key'] ?? ''] + $pick(['export', 'options']),
            self::TYPE_NFS, self::TYPE_CIFS => $pick(['server', 'export', 'options', 'username', 'password']),
            self::TYPE_ISCSI => ['lun' => (int) ($c['lun'] ?? 0)] + $pick(['portal', 'iqn', 'options', 'username', 'password']),
            self::TYPE_STORPOOL => $pick(['template']),
            self::TYPE_LIGHTBITS => ['targets' => array_values((array) ($c['targets'] ?? [])), 'replicas' => (int) ($c['replicas'] ?? 2)]
                + $pick(['nqn', 'endpoint', 'jwt', 'project']),
            default => ['path' => $c['path'] ?? ''],
        };
    }

    /** Suma pojemności z ostatnich odczytów (zasób współdzielony: najświeższy odczyt). */
    public function capacity(): array
    {
        $rows = $this->hypervisors->filter(fn (Hypervisor $h) => $h->pivot->ok && $h->pivot->total_gb !== null);
        if ($rows->isEmpty()) {
            return ['total_gb' => null, 'free_gb' => null, 'checked_at' => null];
        }
        if ($this->isShared()) {
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
