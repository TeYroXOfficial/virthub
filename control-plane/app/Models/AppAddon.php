<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Plugin albo mod zainstalowany z serwisu (Modrinth, Hangar, CurseForge). */
class AppAddon extends Model
{
    protected $fillable = [
        'app_server_id', 'kind', 'source', 'project_id', 'version_id', 'name', 'version', 'filename', 'icon', 'game_version', 'dependency',
    ];

    protected function casts(): array
    {
        return ['dependency' => 'boolean'];
    }

    /** @return BelongsTo<AppServer, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(AppServer::class, 'app_server_id');
    }

    public function directory(): string
    {
        return $this->kind === 'mod' ? 'mods' : 'plugins';
    }
}
