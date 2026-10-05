@extends('layouts.panel')

@section('title', '#'.$ticket->id.' '.$ticket->subject)

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.tickets') }}" style="font-size:13px">{{ __('← Zgłoszenia') }}</a>
            <h1>{{ $ticket->subject }}</h1>
            <div class="meta-line">
                <span class="mono">#{{ $ticket->id }}</span>
                <span class="sep">·</span>
                <span class="pill {{ $ticket->statusTone() }}">{{ $ticket->statusLabel() }}</span>
                <span class="sep">·</span>
                <span class="pill {{ $ticket->priorityTone() }} plain">{{ \App\Models\Ticket::priorityLabel($ticket->priority) }}</span>
                <span class="sep">·</span>
                <span>{{ __('otwarte :time', ['time' => $ticket->created_at->diffForHumans()]) }}</span>
            </div>
        </div>
        @if (auth()->user()->isAdmin())
            <div class="actions">
                <form method="POST" action="{{ route('panel.admin.tickets.destroy', $ticket) }}" style="margin:0" data-confirm="{{ __('Usunąć zgłoszenie razem z wiadomościami i załącznikami?') }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger" type="submit"><x-icon name="trash" :size="15"/> {{ __('Usuń') }}</button>
                </form>
            </div>
        @endif
    </div>

    <div class="ticket-layout">
        <div>
            @include('panel.tickets._thread', ['staffView' => true])

            <div class="card" style="margin-top:16px">
                <h3 class="card-title">{{ __('Odpowiedź') }}</h3>
                <form method="POST" action="{{ route('panel.admin.tickets.reply', $ticket) }}" enctype="multipart/form-data">
                    @csrf
                    @if ($canned->isNotEmpty())
                        <div class="field">
                            <label for="t-canned">{{ __('Gotowa odpowiedź') }}</label>
                            <select id="t-canned" data-canned-target="t-reply">
                                <option value="">{{ __('— wstaw gotową odpowiedź —') }}</option>
                                @foreach ($canned as $c)
                                    <option value="{{ $c->body }}">{{ $c->title }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="field">
                        <textarea id="t-reply" name="body" rows="8" class="prose-input" required maxlength="20000" aria-label="{{ __('Treść odpowiedzi') }}">{{ old('body') }}</textarea>
                    </div>
                    @include('panel.tickets._attachments')
                    <div class="filter-bar">
                        <label class="check-line" style="margin:0"><input type="checkbox" name="internal" value="1"> {{ __('Notatka wewnętrzna (klient jej nie zobaczy)') }}</label>
                        <div class="field" style="margin:0">
                            <label for="t-after">{{ __('Stan po odpowiedzi') }}</label>
                            <select id="t-after" name="status">
                                <option value="answered">{{ __('odpowiedziano') }}</option>
                                <option value="on_hold">{{ __('wstrzymane') }}</option>
                                <option value="closed">{{ __('zamknięte') }}</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" type="submit">{{ __('Wyślij') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <aside class="ticket-side">
            <div class="card">
                <h3 class="card-title">{{ __('Klient') }}</h3>
                @if ($ticket->user)
                    <p style="margin:0"><strong>{{ $ticket->user->name ?: $ticket->user->email }}</strong></p>
                    <p class="hint" style="margin:2px 0 10px">{{ $ticket->user->email }}</p>
                    @if (auth()->user()->hasPermission('admin.users'))
                        <a class="btn btn-sm" href="{{ route('panel.admin.users.edit', $ticket->user) }}">{{ __('Konto klienta') }}</a>
                    @endif
                @else
                    <p class="muted">{{ __('usunięty użytkownik') }}</p>
                @endif
                @if ($service)
                    <dl class="kv" style="margin-top:12px">
                        <dt>{{ __('Usługa') }}</dt>
                        <dd>
                            @if ($service instanceof \App\Models\Server)
                                <a href="{{ route('panel.servers.show', $service) }}">{{ $service->hostname }}</a>
                                <div class="hint">{{ $service->primaryIp()?->address ?? '—' }} · {{ $service->hypervisor?->name ?? '—' }}</div>
                            @else
                                <a href="{{ route('panel.apps.show', $service) }}">{{ $service->name }}</a>
                                <div class="hint">{{ $service->address() ?? '—' }}</div>
                            @endif
                        </dd>
                    </dl>
                @elseif ($ticket->service_type)
                    <p class="hint">{{ __('Powiązana usługa została usunięta.') }}</p>
                @endif
            </div>

            <div class="card" style="margin-top:16px">
                <h3 class="card-title">{{ __('Zgłoszenie') }}</h3>
                <form method="POST" action="{{ route('panel.admin.tickets.update', $ticket) }}">
                    @csrf @method('PUT')
                    <div class="field">
                        <label for="u-status">{{ __('Stan') }}</label>
                        <select id="u-status" name="status">
                            @foreach (\App\Models\Ticket::STATUSES as $s)
                                <option value="{{ $s }}" @selected($ticket->status === $s)>{{ (new \App\Models\Ticket(['status' => $s]))->statusLabel() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="u-prio">{{ __('Priorytet') }}</label>
                        <select id="u-prio" name="priority">
                            @foreach (\App\Models\Ticket::PRIORITIES as $p)
                                <option value="{{ $p }}" @selected($ticket->priority === $p)>{{ \App\Models\Ticket::priorityLabel($p) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="u-dept">{{ __('Dział') }}</label>
                        <select id="u-dept" name="ticket_department_id">
                            <option value="">—</option>
                            @foreach ($departments as $d)
                                <option value="{{ $d->id }}" @selected($ticket->ticket_department_id === $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="u-assign">{{ __('Przypisane do') }}</label>
                        <select id="u-assign" name="assigned_to">
                            <option value="">{{ __('— nikt —') }}</option>
                            @foreach ($staff as $member)
                                <option value="{{ $member->id }}" @selected($ticket->assigned_to === $member->id)>{{ $member->name ?: $member->email }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="btn" type="submit">{{ __('Zapisz') }}</button>
                </form>
            </div>

            @if ($otherTickets->isNotEmpty())
                <div class="card" style="margin-top:16px">
                    <h3 class="card-title">{{ __('Inne zgłoszenia klienta') }}</h3>
                    <ul class="plain-list">
                        @foreach ($otherTickets as $other)
                            <li><a href="{{ route('panel.admin.tickets.show', $other) }}">#{{ $other->id }} {{ $other->subject }}</a> <span class="pill {{ $other->statusTone() }} plain">{{ $other->statusLabel() }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </aside>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('[data-canned-target]').forEach(function (select) {
            select.addEventListener('change', function () {
                if (!select.value) return;
                var area = document.getElementById(select.dataset.cannedTarget);
                area.value = area.value ? area.value.replace(/\s+$/, '') + '\n\n' + select.value : select.value;
                select.value = '';
                area.focus();
            });
        });
    </script>
@endpush
