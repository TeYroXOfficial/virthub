<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * System operacyjny jako całość (np. „Ubuntu"), pod którym są jego wersje —
 * szablony. Klient wybiera najpierw system, potem wersję.
 */
class OsTemplateGroup extends Model
{
    use HasFactory;

    /** Rodziny z kolorem znaczka w panelu. */
    public const FAMILIES = [
        'ubuntu' => 'Ubuntu', 'debian' => 'Debian', 'almalinux' => 'AlmaLinux', 'rocky' => 'Rocky Linux',
        'centos' => 'CentOS', 'fedora' => 'Fedora', 'alpine' => 'Alpine', 'arch' => 'Arch Linux',
        'opensuse' => 'openSUSE', 'windows' => 'Windows', 'linux' => 'Inny Linux',
    ];

    protected $fillable = ['name', 'family', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /**
     * Grupa dla rodziny systemu — istniejąca albo nowa. Szablon dodany bez
     * wskazania grupy (katalog LXC, API, seeder) trafia tu automatycznie.
     */
    public static function forFamily(string $family): self
    {
        return self::query()->where('family', $family)->orderBy('id')->first()
            ?? self::create([
                'name' => self::uniqueName(self::FAMILIES[$family] ?? ucfirst($family)),
                'family' => $family,
                'sort_order' => ((int) self::query()->max('sort_order')) + 10,
                'is_active' => true,
            ]);
    }

    private static function uniqueName(string $name): string
    {
        $candidate = $name;
        for ($i = 2; self::query()->where('name', $candidate)->exists(); $i++) {
            $candidate = "{$name} {$i}";
        }

        return $candidate;
    }

    /** @return HasMany<OsTemplate, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(OsTemplate::class)->orderBy('sort_order')->orderByDesc('version');
    }

    /** @param Builder<OsTemplateGroup> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
