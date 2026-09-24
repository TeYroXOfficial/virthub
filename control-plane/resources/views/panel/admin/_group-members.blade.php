{{-- Lista węzłów do zaznaczenia jako członkowie grupy. $group = null przy tworzeniu. --}}
<div class="field">
    <label>Węzły w grupie</label>
    @forelse ($hypervisors as $node)
        <label style="font-weight:400; display:flex; gap:8px; align-items:center;">
            <input type="checkbox" name="hypervisor_ids[]" value="{{ $node->id }}" style="width:auto"
                   @checked($group && $node->hypervisor_group_id === $group->id)>
            {{ $node->name }}
            @if ($node->group && (! $group || $node->hypervisor_group_id !== $group->id))
                <span class="muted">(teraz w grupie {{ $node->group->name }} — zostanie przeniesiony)</span>
            @endif
        </label>
    @empty
        <p class="muted">Brak węzłów — dodaj je w zakładce Hypervisory.</p>
    @endforelse
    <div class="hint">Węzeł należy do najwyżej jednej grupy.</div>
</div>
