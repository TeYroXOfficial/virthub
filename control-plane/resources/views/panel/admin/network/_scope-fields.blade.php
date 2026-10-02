{{-- Przypisanie bloku: jeden węzeł albo cała grupa węzłów. $current — edytowany blok albo null. --}}
@php
    $scopeValue = old('scope', $current?->isGroupPool() ? 'group' : 'hypervisor');
@endphp
<div class="field">
    <label for="{{ $idp }}-scope">{{ __('Przypisanie') }}</label>
    <select id="{{ $idp }}-scope" name="scope" data-scope-select>
        <option value="hypervisor" @selected($scopeValue === 'hypervisor')>{{ __('Jeden węzeł (hypervisor)') }}</option>
        <option value="group" @selected($scopeValue === 'group') @disabled($groups->isEmpty())>
            {{ __('Grupa węzłów:niej', ['niej' => $groups->isEmpty() ? __(' (najpierw utwórz grupę)') : '']) }}
        </option>
    </select>
    <div class="hint">{{ __('Blok grupy obsługuje wszystkie jej węzły — podsieć musi docierać do każdego z nich.') }}</div>
</div>
<div class="field" data-scope="hypervisor">
    <label for="{{ $idp }}-node">{{ __('Węzeł') }}</label>
    <select id="{{ $idp }}-node" name="hypervisor_id">
        @forelse ($hypervisors as $node)
            <option value="{{ $node->id }}" @selected((int) old('hypervisor_id', $current?->hypervisor_id) === $node->id)>
                {{ $node->name }}{{ $node->group ? ' ('.$node->group->name.')' : '' }}
            </option>
        @empty
            <option value="" disabled>{{ __('Najpierw dodaj hypervisor') }}</option>
        @endforelse
    </select>
</div>
<div class="field" data-scope="group">
    <label for="{{ $idp }}-group">{{ __('Grupa węzłów') }}</label>
    <select id="{{ $idp }}-group" name="hypervisor_group_id">
        <option value="">—</option>
        @foreach ($groups as $group)
            <option value="{{ $group->id }}" @selected((int) old('hypervisor_group_id', $current?->hypervisor_group_id) === $group->id)>{{ $group->name }}</option>
        @endforeach
    </select>
</div>
