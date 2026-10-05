@extends('layouts.panel')

@section('title', '#'.$ticket->id.' '.$ticket->subject)

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.tickets.index') }}" style="font-size:13px">{{ __('← Zgłoszenia') }}</a>
            <h1>{{ $ticket->subject }}</h1>
            <div class="meta-line">
                <span class="mono">#{{ $ticket->id }}</span>
                <span class="sep">·</span>
                <span class="pill {{ $ticket->statusTone() }}">{{ $ticket->statusLabel() }}</span>
                <span class="sep">·</span>
                <span>{{ $ticket->department?->name ?? '—' }}</span>
                <span class="sep">·</span>
                <span class="pill {{ $ticket->priorityTone() }} plain">{{ \App\Models\Ticket::priorityLabel($ticket->priority) }}</span>
                @if ($service)
                    <span class="sep">·</span>
                    @if ($service instanceof \App\Models\Server)
                        <a href="{{ route('panel.servers.show', $service) }}">{{ $service->hostname }}</a>
                    @else
                        <a href="{{ route('panel.apps.show', $service) }}">{{ $service->name }}</a>
                    @endif
                @endif
            </div>
        </div>
        <div class="actions">
            @if ($ticket->isClosed())
                <form method="POST" action="{{ route('panel.tickets.reopen', $ticket) }}" style="margin:0">
                    @csrf
                    <button class="btn" type="submit"><x-icon name="refresh" :size="15"/> {{ __('Otwórz ponownie') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('panel.tickets.close', $ticket) }}" style="margin:0" data-confirm="{{ __('Zamknąć zgłoszenie? Możesz je potem otworzyć ponownie.') }}">
                    @csrf
                    <button class="btn" type="submit">{{ __('Zamknij zgłoszenie') }}</button>
                </form>
            @endif
        </div>
    </div>

    @include('panel.tickets._thread', ['staffView' => false])

    <div class="card" style="margin-top:16px">
        <h3 class="card-title">{{ $ticket->isClosed() ? __('Odpowiedz, żeby otworzyć ponownie') : __('Odpowiedz') }}</h3>
        <form method="POST" action="{{ route('panel.tickets.reply', $ticket) }}" enctype="multipart/form-data">
            @csrf
            <div class="field">
                <textarea name="body" rows="6" class="prose-input" required maxlength="20000" aria-label="{{ __('Treść odpowiedzi') }}">{{ old('body') }}</textarea>
            </div>
            @include('panel.tickets._attachments')
            <button class="btn btn-primary" type="submit">{{ __('Wyślij odpowiedź') }}</button>
        </form>
    </div>
@endsection
