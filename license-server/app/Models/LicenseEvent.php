<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['license_id', 'action', 'meta', 'ip'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public static function record(?License $license, string $action, array $meta = []): void
    {
        static::query()->create(['license_id' => $license?->id, 'action' => $action, 'meta' => $meta, 'ip' => request()?->ip()]);
    }
}
