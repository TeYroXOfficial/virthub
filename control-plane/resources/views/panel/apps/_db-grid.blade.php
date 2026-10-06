{{-- Siatka wyników: $columns, $rows. --}}
<div class="table-wrap">
    <table class="db-grid">
        <thead><tr>@foreach ($columns as $col)<th>{!! $head($col) !!}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                @foreach ($columns as $col)
                    @php $val = $row[$col] ?? null; @endphp
                    @if ($val === null)
                        <td class="null">NULL</td>
                    @else
                        @php $text = mb_check_encoding((string) $val, 'UTF-8') ? (string) $val : '0x'.bin2hex(substr((string) $val, 0, 64)); @endphp
                        <td title="{{ \Illuminate\Support\Str::limit($text, 1000) }}">{{ \Illuminate\Support\Str::limit($text, 120) }}</td>
                    @endif
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ max(1, count($columns)) }}" class="muted" style="font-family:inherit">{{ __('Brak wierszy.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
