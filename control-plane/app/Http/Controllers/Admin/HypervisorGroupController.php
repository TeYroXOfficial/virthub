<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Network\HypervisorGroupManager;
use App\Http\Controllers\Controller;
use App\Models\HypervisorGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HypervisorGroupController extends Controller
{
    public function __construct(private readonly HypervisorGroupManager $groups) {}

    public function index(): JsonResponse
    {
        $groups = HypervisorGroup::query()
            ->with('hypervisors:id,name,hypervisor_group_id')
            ->withCount('ipPools')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $groups->map(fn (HypervisorGroup $group) => $this->present($group)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:hypervisor_groups,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'hypervisor_ids' => ['array'],
            'hypervisor_ids.*' => ['integer', 'exists:hypervisors,id'],
        ]);

        $group = $this->groups->create(
            $validated['name'],
            $validated['description'] ?? null,
            $validated['hypervisor_ids'] ?? [],
        );

        return response()->json(['data' => $this->present($group->load('hypervisors'))], 201);
    }

    /** Pominięte hypervisor_ids zostawia skład bez zmian; pusta lista opróżnia grupę. */
    public function update(Request $request, HypervisorGroup $group): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('hypervisor_groups', 'name')->ignore($group->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'hypervisor_ids' => ['sometimes', 'array'],
            'hypervisor_ids.*' => ['integer', 'exists:hypervisors,id'],
        ]);

        $this->groups->update(
            $group,
            $validated['name'] ?? $group->name,
            array_key_exists('description', $validated) ? $validated['description'] : $group->description,
            $request->has('hypervisor_ids') ? ($validated['hypervisor_ids'] ?? []) : null,
        );

        return response()->json(['data' => $this->present($group->fresh('hypervisors'))]);
    }

    public function destroy(HypervisorGroup $group): JsonResponse
    {
        $this->groups->delete($group);

        return response()->json(['message' => 'Grupa została usunięta.']);
    }

    /** @return array<string, mixed> */
    private function present(HypervisorGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'hypervisors' => $group->hypervisors->map->only(['id', 'name'])->values(),
            'ip_pools' => $group->ip_pools_count ?? $group->ipPools()->count(),
        ];
    }
}
