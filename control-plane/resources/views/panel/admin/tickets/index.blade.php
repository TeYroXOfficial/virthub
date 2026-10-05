@extends('layouts.panel')

@section('title', __('Zgłoszenia'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Zgłoszenia') }}</h1>
            <p class="lede">{{ __('Kolejka wsparcia — najpierw pilne i czekające najdłużej.') }}</p>
        </div>
        <div class="actions"><a class="btn" href="{{ route('panel.admin.tickets.settings') }}"><x-icon name="sliders" :size="15"/> {{ __('Działy i odpowiedzi') }}</a></div>
    </div>

    <div class="grid grid-4" style="margin-bottom:16px">
        <a class="stat stat-link" href="{{ route('panel.admin.tickets', ['status' => 'awaiting']) }}"><div class="stat-label"><i class="dot warning"></i> {{ __('Czekają na odpowiedź') }}</div><div class="stat-value">{{ $counts['awaiting'] }}</div></a>
        <a class="stat stat-link" href="{{ route('panel.admin.tickets', ['mine' => 1]) }}"><div class="stat-label">{{ __('Przypisane do mnie') }}</div><div class="stat-value">{{ $counts['mine'] }}</div></a>
        <a class="stat stat-link" href="{{ route('panel.admin.tickets', ['priority' => 'urgent']) }}"><div class="stat-label"><i class="dot critical"></i> {{ __('Pilne') }}</div><div class="stat-value">{{ $counts['urgent'] }}</div></a>
        <a class="stat stat-link" href="{{ route('panel.admin.tickets', ['status' => 'on_hold']) }}"><div class="stat-label">{{ __('Wstrzymane') }}</div><div class="stat-value">{{ $counts['on_hold'] }}</div></a>
    </div>

    <form class="card filter-bar" method="GET" action="{{ route('panel.admin.tickets') }}" style="margin-bottom:16px">
        <div class="field" style="margin:0">
            <label for="f-q">{{ __('Szukaj') }}</label>
            <input id="f-q" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('#numer, temat, e-mail') }}">
        </div>
        <div class="field" style="margin:0">
            <label for="f-status">{{ __('Stan') }}</label>
            <select id="f-status" name="status">
                @foreach (['active' => __('Nie zamknięte'), 'awaiting' => __('Czekają na nas'), 'open' => __('otwarte'), 'answered' => __('odpowiedziano'), 'customer_reply' => __('odpowiedź klienta'), 'on_hold' => __('wstrzymane'), 'closed' => __('zamknięte'), 'all' => __('Wszystkie')] as $k => $label)
                    <option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0">
            <label for="f-dept">{{ __('Dział') }}</label>
            <select id="f-dept" name="department">
                <option value="">{{ __('Wszystkie') }}</option>
                @foreach ($departments as $d)
                    <option value="{{ $d->id }}" @selected(($filters['department'] ?? null) == $d->id)>{{ $d->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0">
            <label for="f-prio">{{ __('Priorytet') }}</label>
            <select id="f-prio" name="priority">
                <option value="">{{ __('Wszystkie') }}</option>
                @foreach (\App\Models\Ticket::PRIORITIES as $p)
                    <option value="{{ $p }}" @selected(($filters['priority'] ?? null) === $p)>{{ \App\Models\Ticket::priorityLabel($p) }}</option>
                @endforeach
            </select>
        </div>
        <label class="check-line" style="margin:0 0 10px"><input type="checkbox" name="mine" value="1" @checked(! empty($filters['mine']))> {{ __('Tylko moje') }}</label>
        <button class="btn" type="submit"><x-icon name="search" :size="15"/> {{ __('Filtruj') }}</button>
    </form>

    @if ($tickets->isEmpty())
        <div class="card empty"><x-icon name="ticket" :size="40"/><p>{{ __('Brak zgłoszeń w tym widoku.') }}</p></div>
    @else
        <div class="card flush">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th>{{ __('Temat') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Dział') }}</th><th>{{ __('Priorytet') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Przypisane') }}</th><th>{{ __('Ostatnia odpowiedź') }}</th></tr></thead>
                    <tbody>
                    @foreach ($tickets as $ticket)
                        <tr @class(['ticket-awaiting' => in_array($ticket->status, \App\Models\Ticket::AWAITING_STAFF, true)])>
                            <td class="mono muted">{{ $ticket->id }}</td>
                            <td><a href="{{ route('panel.admin.tickets.show', $ticket) }}" style="font-weight:600">{{ $ticket->subject }}</a></td>
                            <td>{{ $ticket->user?->email ?? '—' }}</td>
                            <td>{{ $ticket->department?->name ?? '—' }}</td>
                            <td><span class="pill {{ $ticket->priorityTone() }} plain">{{ \App\Models\Ticket::priorityLabel($ticket->priority) }}</span></td>
                            <td><span class="pill {{ $ticket->statusTone() }}">{{ $ticket->statusLabel() }}</span></td>
                            <td>{{ $ticket->assignee?->name ?: ($ticket->assignee?->email ?? '—') }}</td>
                            <td class="muted nowrap">{{ $ticket->last_reply_at?->diffForHumans() ?? '—' }}
                                @if ($ticket->last_reply_by === 'customer' && ! $ticket->isClosed()) <div class="hint">{{ __('klient') }}</div> @endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        {{ $tickets->links() }}
    @endif
@endsection
