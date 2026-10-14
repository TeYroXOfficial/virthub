@extends('layouts.panel')

@section('title', __('Mailouty'))

@section('content')
    <div class="page-header">
        <div><h1>{{ __('Mailouty') }}</h1>
            <p class="lede">{{ __('Masowe wiadomości do klientów: ogłoszenia, prace serwisowe, nowości — do wybranej grupy, ze zmiennymi.') }}</p></div>
        <div class="actions"><a class="btn btn-primary" href="{{ route('panel.admin.mailouts.create') }}"><x-icon name="plus" :size="16"/> {{ __('Nowy mailout') }}</a></div>
    </div>

    @php $statusLabel = ['draft' => __('szkic'), 'sending' => __('wysyłanie'), 'sent' => __('wysłany'), 'cancelled' => __('zatrzymany')];
         $statusTone = ['draft' => 'neutral', 'sending' => 'warning', 'sent' => 'ok', 'cancelled' => 'critical']; @endphp
    <div class="card flush dash-section">
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Temat') }}</th><th>{{ __('Odbiorcy') }}</th><th>{{ __('Status') }}</th><th class="num">{{ __('Wysłane') }}</th><th>{{ __('Utworzony') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($mailouts as $mailout)
                    <tr>
                        <td><strong>{{ $mailout->subject }}</strong>
                            @unless ($mailout->respect_opt_out) <span class="pill info plain">{{ __('serwisowy') }}</span> @endunless</td>
                        <td>{{ $audiences[$mailout->audience['type'] ?? 'all'] ?? '—' }}</td>
                        <td><span class="pill {{ $statusTone[$mailout->status] ?? 'neutral' }}">{{ $statusLabel[$mailout->status] ?? $mailout->status }}</span></td>
                        <td class="num">{{ $mailout->status === 'draft' ? '—' : $mailout->sent_count.' / '.$mailout->total }}
                            @if ($mailout->failed_count) <span class="pill critical plain">{{ $mailout->failed_count }}</span> @endif</td>
                        <td>{{ $mailout->created_at->format('d.m.Y H:i') }} <span class="muted">{{ $mailout->author?->email }}</span></td>
                        <td style="text-align:right"><a class="btn btn-sm" href="{{ route('panel.admin.mailouts.show', $mailout) }}">{{ $mailout->editable() ? __('Edytuj') : __('Szczegóły') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('Brak mailoutów.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $mailouts->links() }}
@endsection
