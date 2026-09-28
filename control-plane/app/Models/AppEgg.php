<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Szablon aplikacji w formacie eggów Pterodactyla: obrazy uruchomieniowe,
 * polecenie startowe, skrypt instalacyjny i zmienne, które klient ustawia.
 */
class AppEgg extends Model
{
    public const CATEGORIES = ['game' => 'Serwery gier', 'bot' => 'Boty', 'web' => 'Strony i API', 'other' => 'Inne'];

    protected $fillable = [
        'name', 'category', 'description', 'author', 'docker_images', 'startup', 'stop_command',
        'startup_done', 'config_files', 'install_image', 'install_entrypoint', 'install_script',
        'variables', 'features', 'source', 'builtin_key', 'is_active',
    ];

    protected $attributes = ['is_active' => true, 'category' => 'game', 'stop_command' => '^C', 'source' => 'import'];

    protected function casts(): array
    {
        return [
            'docker_images' => 'array',
            'config_files' => 'array',
            'variables' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<AppServer, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(AppServer::class);
    }

    /** @param  Builder<AppEgg>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function categoryLabel(): string
    {
        return __(self::CATEGORIES[$this->category] ?? self::CATEGORIES['other']);
    }

    /** @return array<string, string> etykieta → obraz */
    public function images(): array
    {
        return $this->docker_images ?: [];
    }

    public function defaultImage(): ?string
    {
        return array_values($this->images())[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function variableList(): array
    {
        return array_values($this->variables ?? []);
    }

    /** Zmienne, które klient widzi (i ewentualnie zmienia). */
    public function visibleVariables(): array
    {
        return array_values(array_filter($this->variableList(), fn ($v) => $v['user_viewable'] ?? true));
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? [], true);
    }
}
