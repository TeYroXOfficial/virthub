@php
    $family = strtolower($template->family ?? '');
    [$bg, $fg] = \App\Support\OsIcon::colors($family);
    $logo = \App\Support\OsIcon::path($family);
@endphp
<span class="os-badge" style="--os: {{ $bg }}; color: {{ $fg }}" aria-hidden="true">
    @if ($logo)
        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">{!! $logo !!}</svg>
    @else
        {{ mb_strtoupper(mb_substr($family ?: $template->name, 0, 2)) }}
    @endif
</span>
