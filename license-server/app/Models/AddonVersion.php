<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddonVersion extends Model
{
    protected $fillable = ['addon_id', 'version', 'sha256', 'signature', 'path', 'size', 'changelog', 'is_published'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'size' => 'integer'];
    }

    /** @return BelongsTo<Addon, $this> */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}
