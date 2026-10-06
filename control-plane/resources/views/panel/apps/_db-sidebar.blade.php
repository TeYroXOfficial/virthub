{{-- Lista tabel w przeglądarce bazy. --}}
<div class="card flush">
    <div class="dash-head" style="padding:12px 14px">
        <a href="{{ route('panel.apps.databases.browse', [$app, $database]) }}" class="mono" style="font-weight:600; color:inherit; text-decoration:none"><x-icon name="database" :size="14"/> {{ $database->database }}</a>
    </div>
    <ul class="db-tables">
        @forelse ($tables as $t)
            <li><a href="{{ route('panel.apps.databases.table', [$app, $database, $t['name']]) }}" @if (($table ?? null) === $t['name']) aria-current="page" @endif>
                <span class="mono">{{ $t['name'] }}</span>
                <span class="hint">{{ $t['type'] === 'VIEW' ? __('widok') : '~'.number_format($t['rows'], 0, ',', ' ') }}</span></a></li>
        @empty
            <li class="muted" style="padding:6px 14px; font-size:13px">{{ __('Brak tabel.') }}</li>
        @endforelse
    </ul>
</div>
