@php
    $family = strtolower($template->family ?? '');
    $colors = [
        'ubuntu' => '#e95420', 'debian' => '#d70a53', 'almalinux' => '#0f4266', 'rocky' => '#10b981',
        'centos' => '#932279', 'fedora' => '#51a2da', 'alpine' => '#0d597f', 'arch' => '#1793d1',
        'windows' => '#0078d4', 'opensuse' => '#73ba25',
    ];
    $color = $colors[$family] ?? '#677789';
    $initials = mb_strtoupper(mb_substr($family ?: $template->name, 0, 2));
@endphp
<span class="os-badge" style="--os: {{ $color }}" aria-hidden="true">{{ $initials }}</span>
