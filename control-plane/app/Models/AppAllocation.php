<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Port węzła przydzielony aplikacji (TCP i UDP). */
class AppAllocation extends Model
{
    protected $fillable = ['hypervisor_id', 'app_server_id', 'port', 'is_primary'];

    protected function casts(): array
    {
        return ['port' => 'integer', 'is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Hypervisor, $this> */
    public function hypervisor(): BelongsTo
    {
        return $this->belongsTo(Hypervisor::class);
    }

    /** @return BelongsTo<AppServer, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(AppServer::class, 'app_server_id');
    }
}
