@extends('layouts.panel')

@section('title', __('Zgłoszenia'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Zgłoszenia') }}</h1>
            <p class="lede">{{ __('Napisz do nas, jeśli coś nie działa albo masz pytanie o usługi i płatności.') }}</p>
        </div>
        <div class="actions">
            <a class="btn btn-primary" href="{{ route('panel.tickets.create') }}"><x-icon name="plus" :size="16"/> {{ __('Nowe zgłoszenie') }}</a>
        </div>
    </div>

    <nav class="subnav">
        @foreach ([null => __('Wszystkie'), 'open' => __('Otwarte'), 'closed' => __('Zamknięte')] as $key => $label)
            <a href="{{ route('panel.tickets.index', $key ? ['status' => $key] : []) }}" @if ($status === ($key ?: null)) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    @if ($tickets->isEmpty())
        <div class="card empty">
            <x-icon name="ticket" :size="40"/>
            <p>{{ __('Nie masz żadnych zgłoszeń.') }}</p>
            <a class="btn btn-primary" href="{{ route('panel.tickets.create') }}">{{ __('Napisz do wsparcia') }}</a>
        </div>
    @else
        <div class="card flush">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th>{{ __('Temat') }}</th><th>{{ __('Dział') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Ostatnia odpowiedź') }}</th></tr></thead>
                    <tbody>
                    @foreach ($tickets as $ticket)
                        <tr>
                            <td class="mono muted">{{ $ticket->id }}</td>
                            <td><a href="{{ route('panel.tickets.show', $ticket) }}" style="font-weight:600">{{ $ticket->subject }}</a></td>
                            <td>{{ $ticket->department?->name ?? '—' }}</td>
                            <td><span class="pill {{ $ticket->statusTone() }}">{{ $ticket->statusLabel() }}</span></td>
                            <td class="muted nowrap">{{ $ticket->last_reply_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        {{ $tickets->links() }}
    @endif
@endsection
