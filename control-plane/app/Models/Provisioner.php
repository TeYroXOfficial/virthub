<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Skrypt pierwszego uruchomienia przypinany do szablonów systemu. Agent
 * zapisuje go w maszynie i uruchamia przez cloud-init (tylko Linux).
 */
class Provisioner extends Model
{
    protected $fillable = ['name', 'description', 'content', 'path', 'is_active'];

    protected $attributes = ['path' => '/var/lib/virthub/provision', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsToMany<OsTemplate, $this> */
    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(OsTemplate::class)->withPivot('sort_order');
    }
}
