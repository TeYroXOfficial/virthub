{{-- Tabela wpisów dziennika zdarzeń (pulpit i pełny dziennik). --}}
<div class="table-wrap">
    <table>
        <thead><tr><th>{{ __('Zdarzenie') }}</th><th>{{ __('Kto') }}</th><th>{{ __('Czego dotyczy') }}</th><th>{{ __('Adres IP') }}</th><th>{{ __('Kiedy') }}</th></tr></thead>
        <tbody>
        @forelse ($logs as $log)
            <tr>
                <td class="mono">{{ $log->action }}</td>
                <td>{{ $log->actor?->email ?? $log->actor_label ?? '—' }}</td>
                <td class="muted">{{ $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '—' }}</td>
                <td class="mono muted">{{ $log->ip_address ?? '—' }}</td>
                <td class="muted nowrap" title="{{ $log->created_at->format('d.m.Y H:i:s') }}">{{ $log->created_at->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted" style="text-align:center; padding:24px">{{ __('Brak zdarzeń.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
