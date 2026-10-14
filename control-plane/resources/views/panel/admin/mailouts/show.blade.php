@extends('layouts.panel')

@section('title', $mailout->subject)

@section('content')
    @php $statusLabel = ['draft' => __('szkic'), 'sending' => __('wysyłanie'), 'sent' => __('wysłany'), 'cancelled' => __('zatrzymany')]; @endphp
    <div class="page-header">
        <div><h1>{{ $mailout->subject }}</h1>
            <div class="meta-line"><span>{{ $audiences[$mailout->audience['type'] ?? 'all'] ?? '—' }}</span>
                <span>{{ $statusLabel[$mailout->status] ?? $mailout->status }}</span>
                @if ($mailout->started_at) <span>{{ $mailout->started_at->format('d.m.Y H:i') }}</span> @endif</div></div>
        <div class="btn-row">
            <a class="btn" href="{{ route('panel.admin.mailouts') }}">{{ __('← Mailouty') }}</a>
            @if ($mailout->status === 'sending')
                <form method="POST" action="{{ route('panel.admin.mailouts.cancel', $mailout) }}" style="margin:0" data-confirm="{{ __('Zatrzymać wysyłkę? Niewysłane wiadomości zostaną pominięte.') }}">@csrf
                    <button class="btn btn-danger" type="submit">{{ __('Zatrzymaj') }}</button></form>
            @endif
        </div>
    </div>

    @php $pct = $mailout->total ? (int) floor(($mailout->sent_count + $mailout->failed_count) * 100 / $mailout->total) : 100; @endphp
    <div class="card">
        <div class="meter" style="margin-bottom:8px"><i style="width:{{ $pct }}%"></i></div>
        <p class="muted" style="margin:0">{{ __('Wysłane: :sent z :total, nieudane: :failed.', ['sent' => $mailout->sent_count, 'total' => $mailout->total, 'failed' => $mailout->failed_count]) }}</p>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title"><x-icon name="mail" :size="16"/> {{ __('Treść') }}</h3>
            <div style="border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; background:#fff; color:#0f1a26">{!! $preview['html'] !!}</div>
        </div>
        <div class="card flush">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Odbiorca') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @php $rLabel = ['pending' => __('w kolejce'), 'sent' => __('wysłany'), 'failed' => __('nieudany'), 'skipped' => __('pominięty')];
                         $rTone = ['pending' => 'warning', 'sent' => 'ok', 'failed' => 'critical', 'skipped' => 'neutral']; @endphp
                    @foreach ($recipients as $r)
                        <tr><td>{{ $r->email }}</td>
                            <td><span class="pill {{ $rTone[$r->status] ?? 'neutral' }}">{{ $rLabel[$r->status] ?? $r->status }}</span>
                                @if ($r->error) <div class="hint">{{ \Illuminate\Support\Str::limit($r->error, 80) }}</div> @endif</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    {{ $recipients->links() }}
@endsection
