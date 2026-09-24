<?php

namespace App\Domain\Provisioning;

use App\Models\OsTemplate;
use App\Models\OsTemplateGroup;
use Illuminate\Support\Collection;

/**
 * Systemy do wyboru przez klienta: grupa (np. „Ubuntu") i jej wersje.
 * Ten sam układ widać przy zamówieniu i przy reinstalacji.
 */
class TemplateCatalog
{
    /**
     * @param  list<string>  $virtualizations  rodzaje, które da się teraz postawić
     * @return Collection<int, array{key: string, name: string, family: string, description: ?string, templates: Collection<int, OsTemplate>}>
     */
    public function choices(array $virtualizations): Collection
    {
        $templates = OsTemplate::query()
            ->with('group')
            ->active()
            ->where('cloud_init_support', true)
            ->whereIn('virtualization', $virtualizations)
            ->get()
            ->filter(fn (OsTemplate $t) => $t->group === null || $t->group->is_active);

        return $templates
            ->groupBy(fn (OsTemplate $t) => $t->os_template_group_id ?? 0)
            ->map(function (Collection $versions, int $groupId) {
                /** @var OsTemplateGroup|null $group */
                $group = $versions->first()->group;

                return [
                    'key' => $groupId ? "g{$groupId}" : 'other',
                    'name' => $group?->name ?? 'Inne systemy',
                    'family' => $group?->family ?? 'linux',
                    'description' => $group?->description,
                    'sort' => $group?->sort_order ?? PHP_INT_MAX,
                    'templates' => $versions
                        ->sortBy([['sort_order', 'asc'], [fn ($a, $b) => version_compare((string) $b->version, (string) $a->version)]])
                        ->values(),
                ];
            })
            ->sortBy([['sort', 'asc'], ['name', 'asc']])
            ->values();
    }
}
