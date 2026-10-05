@extends('layouts.panel')

@section('title', __('Nowe zgłoszenie'))

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.tickets.index') }}" style="font-size:13px">{{ __('← Zgłoszenia') }}</a>
            <h1>{{ __('Nowe zgłoszenie') }}</h1>
        </div>
    </div>

    @if ($departments->isEmpty())
        <div class="card empty"><p>{{ __('Zgłoszenia są chwilowo niedostępne.') }}</p></div>
    @else
        <div class="card" style="max-width:820px">
            <form method="POST" action="{{ route('panel.tickets.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="grid grid-2">
                    <div class="field">
                        <label for="t-dept">{{ __('Dział') }}</label>
                        <select id="t-dept" name="department_id" required>
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}" @selected(old('department_id') == $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                        @if ($departments->contains(fn ($d) => $d->description))
                            <div class="hint">@foreach ($departments as $d)@if ($d->description)<strong>{{ $d->name }}</strong> — {{ $d->description }}<br>@endif @endforeach</div>
                        @endif
                    </div>
                    <div class="field">
                        <label for="t-prio">{{ __('Priorytet') }}</label>
                        <select id="t-prio" name="priority">
                            @foreach (\App\Models\Ticket::PRIORITIES as $p)
                                <option value="{{ $p }}" @selected(old('priority', 'medium') === $p)>{{ \App\Models\Ticket::priorityLabel($p) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label for="t-service">{{ __('Usługa, której dotyczy') }}</label>
                    <select id="t-service" name="service">
                        <option value="">{{ __('— żadna / ogólne pytanie —') }}</option>
                        @foreach ($services as $value => $label)
                            <option value="{{ $value }}" @selected(old('service', $selected) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="t-subject">{{ __('Temat') }}</label>
                    <input id="t-subject" name="subject" type="text" maxlength="150" required value="{{ old('subject') }}">
                </div>
                <div class="field">
                    <label for="t-body">{{ __('Opis') }}</label>
                    <textarea id="t-body" name="body" rows="10" class="prose-input" required maxlength="20000">{{ old('body') }}</textarea>
                    <div class="hint">{{ __('Opisz, co się dzieje, od kiedy i co już sprawdzałeś. Nie wysyłaj haseł.') }}</div>
                </div>
                @include('panel.tickets._attachments')
                <button class="btn btn-primary" type="submit">{{ __('Wyślij zgłoszenie') }}</button>
            </form>
        </div>
    @endif
@endsection
