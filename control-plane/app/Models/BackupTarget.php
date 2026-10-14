<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Miejsce na kopie zapasowe: katalog na węźle, S3 albo SFTP. Dane dostępowe szyfrowane. */
class BackupTarget extends Model
{
    public const TYPES = ['local', 's3', 'sftp'];

    protected $fillable = ['name', 'type', 'config', 'is_active'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'is_active' => 'boolean'];
    }

    /** @return HasMany<BackupPlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(BackupPlan::class);
    }

    /** @return array{type:string, config:array<string, mixed>} */
    public function toAgentPayload(): array
    {
        return ['type' => $this->type, 'config' => (array) $this->config];
    }

    public function describe(): string
    {
        $c = (array) $this->config;

        return match ($this->type) {
            's3' => ($c['endpoint'] ?? '').'/'.($c['bucket'] ?? '').(! empty($c['prefix']) ? '/'.$c['prefix'] : ''),
            'sftp' => ($c['username'] ?? '').'@'.($c['host'] ?? '').':'.($c['path'] ?? ''),
            default => (string) ($c['path'] ?? ''),
        };
    }
}
