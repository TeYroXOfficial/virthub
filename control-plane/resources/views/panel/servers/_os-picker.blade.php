{{-- Wybór systemu: kafelek grupy (Ubuntu, Debian…) + wersja z listy.
     $osChoices z TemplateCatalog, $selected — id szablonu, $current — obecny system maszyny. --}}
@php
    $selected = (int) ($selected ?? 0);
    $current = $current ?? null;
    $selectedKey = $osChoices->first(fn ($c) => $c['templates']->contains('id', $selected))['key'] ?? null;
@endphp
<div class="os-picker" data-os-picker>
    <input type="hidden" name="template" value="{{ $selected ?: '' }}" data-os-value>
    <div class="os-grid" role="radiogroup" aria-label="System operacyjny">
        @foreach ($osChoices as $choice)
            @php
                $mixed = $choice['templates']->pluck('virtualization')->unique()->count() > 1;
                $first = $choice['templates']->first();
                $picked = $choice['templates']->firstWhere('id', $selected) ?? $first;
            @endphp
            <div class="os-card @if($selectedKey === $choice['key']) is-selected @endif" data-os-group tabindex="0"
                 role="radio" aria-checked="{{ $selectedKey === $choice['key'] ? 'true' : 'false' }}">
                @include('panel.servers._os-badge', ['template' => (object) ['family' => $choice['family'], 'name' => $choice['name']]])
                <div class="os-card-body">
                    <span class="os-name">{{ $choice['name'] }}</span>
                    @if ($choice['templates']->count() > 1)
                        <select class="os-version" data-os-version aria-label="Wersja {{ $choice['name'] }}">
                            @foreach ($choice['templates'] as $t)
                                <option value="{{ $t->id }}" @selected($picked->id === $t->id)>
                                    {{ $t->versionLabel() }}@if($mixed) ({{ $t->virtualization->shortLabel() }})@endif
                                </option>
                            @endforeach
                        </select>
                    @else
                        <span class="os-version-one" data-os-single="{{ $first->id }}">
                            {{ $first->versionLabel() }}
                        </span>
                    @endif
                    @if ($current && ($now = $choice['templates']->firstWhere('id', $current)))
                        <span class="os-current-note">obecnie: {{ $now->versionLabel() }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
