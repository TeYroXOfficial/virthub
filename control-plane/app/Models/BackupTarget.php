<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Miejsce na kopie zapasowe: katalog na węźle, S3 albo SFTP. Dane dostępowe szyfrowane. */
class BackupTarget extends Model
{
    public const TYPES = ['local', 's3', 'sftp', 'ftp', 'rclone', 'pbs'];

    protected $fillable = ['name', 'type', 'config', 'is_active', 'encryption_key'];

    protected $hidden = ['config', 'encryption_key'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'is_active' => 'boolean', 'encryption_key' => 'encrypted'];
    }

    /** @return HasMany<BackupPlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(BackupPlan::class);
    }

    /** @return array{type:string, config:array<string, mixed>} */
    public function toAgentPayload(): array
    {
        return array_filter(['type' => $this->type, 'config' => (array) $this->config, 'encryption_key' => $this->encryption_key], fn ($v) => $v !== null);
    }

    public function isEncrypted(): bool
    {
        return $this->encryption_key !== null;
    }

    /** Nowy klucz AES-256 (base64) — kopie są szyfrowane na węźle przed wysyłką. */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function describe(): string
    {
        $c = (array) $this->config;

        return match ($this->type) {
            's3' => ($c['endpoint'] ?? '').'/'.($c['bucket'] ?? '').(! empty($c['prefix']) ? '/'.$c['prefix'] : ''),
            'sftp' => ($c['username'] ?? '').'@'.($c['host'] ?? '').':'.($c['path'] ?? ''),
            'ftp' => (($c['tls'] ?? true) ? 'ftps://' : 'ftp://').($c['username'] ?? '').'@'.($c['host'] ?? '').($c['path'] ?? ''),
            'rclone' => ($c['backend'] ?? '').':'.($c['path'] ?? ''),
            'pbs' => ($c['username'] ?? '').'@'.($c['host'] ?? '').':'.($c['datastore'] ?? '').(! empty($c['namespace']) ? ' ns='.$c['namespace'] : ''),
            default => (string) ($c['path'] ?? ''),
        };
    }
}
