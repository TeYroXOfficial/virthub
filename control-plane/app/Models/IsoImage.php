<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IsoImage extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'filename', 'url', 'sha256', 'size_bytes', 'is_public', 'server_id'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'size_bytes' => 'integer'];
    }

    /** @return HasMany<IsoDownload, $this> */
    public function downloads(): HasMany
    {
        return $this->hasMany(IsoDownload::class);
    }

    /** @return HasMany<Server, $this> */
    /** Właściciel własnego obrazu klienta (null = biblioteka administratora). */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function isCustom(): bool
    {
        return $this->server_id !== null;
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function isReadyOn(?int $hypervisorId): bool
    {
        return $hypervisorId !== null && $this->downloads()
            ->where('hypervisor_id', $hypervisorId)
            ->where('status', IsoDownload::STATUS_READY)
            ->exists();
    }
}
