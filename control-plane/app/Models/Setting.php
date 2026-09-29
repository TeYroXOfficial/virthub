<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Ustawienie panelu (klucz → wartość). Wszystkie trzymamy w jednym wpisie
 * cache, bo czytamy je przy każdym żądaniu (np. konfiguracja poczty).
 */
class Setting extends Model
{
    private const CACHE_KEY = 'virthub.settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** @return array<string, string|null> */
    public static function allValues(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::allValues()[$key] ?? $default;
    }

    /** @param  array<string, string|null>  $values  null usuwa ustawienie */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            $value === null
                ? static::query()->whereKey($key)->delete()
                : static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Cache::forget(self::CACHE_KEY);
    }
}
