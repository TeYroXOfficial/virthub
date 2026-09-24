<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OsTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'os_template_group_id',
        'sort_order',
        'name',
        'family',
        'virtualization',
        'version',
        'image_file',
        'min_disk_gb',
        'cloud_init_support',
        'is_active',
        'icon',
    ];

    protected static function booted(): void
    {
        static::creating(function (OsTemplate $template) {
            if ($template->os_template_group_id === null && $template->family) {
                $template->os_template_group_id = OsTemplateGroup::forFamily($template->family)->id;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'cloud_init_support' => 'boolean',
            'is_active' => 'boolean',
            'virtualization' => \App\Enums\Virtualization::class,
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<TemplateDownload, $this> */
    public function downloads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TemplateDownload::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<OsTemplateGroup, $this> */
    public function group(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OsTemplateGroup::class, 'os_template_group_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Server, $this> */
    public function servers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** Krótka nazwa wersji do listy wyboru w grupie („24.04 LTS"). */
    public function versionLabel(): string
    {
        $group = $this->group?->name;

        if ($group !== null && str_starts_with($this->name, $group)) {
            $rest = trim(substr($this->name, strlen($group)));

            return $rest !== '' ? $rest : $this->version;
        }

        return $this->name;
    }

    public function isContainer(): bool
    {
        return $this->virtualization === \App\Enums\Virtualization::Lxc;
    }

    /** @param Builder<OsTemplate> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Szablon bez cloud-init wymagałby ręcznej instalacji z ISO — do czasu
     * wsparcia tej ścieżki (Windows, Faza po MVP) nie da się go zamówić.
     */
    public function isSelfService(): bool
    {
        return $this->cloud_init_support;
    }
}
