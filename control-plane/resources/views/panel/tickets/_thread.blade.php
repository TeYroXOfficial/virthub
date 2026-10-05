{{-- Wątek zgłoszenia. $staffView — widok personelu (notatki wewnętrzne, adresy e-mail autorów). --}}
<div class="ticket-thread">
    @foreach ($ticket->messages as $message)
        <article class="ticket-msg {{ $message->is_staff ? 'staff' : 'customer' }} {{ $message->is_internal ? 'internal' : '' }}">
            <header>
                <span class="avatar">{{ mb_substr($message->author?->name ?: ($message->author?->email ?: '?'), 0, 1) }}</span>
                <div>
                    <strong>
                        @if ($message->is_staff && ! $staffView)
                            {{ $message->author?->name ?: __('Zespół wsparcia') }}
                        @else
                            {{ $message->author?->name ?: ($message->author?->email ?? __('usunięty użytkownik')) }}
                        @endif
                    </strong>
                    @if ($message->is_internal)
                        <span class="pill warning">{{ __('notatka wewnętrzna') }}</span>
                    @elseif ($message->is_staff)
                        <span class="pill info">{{ __('wsparcie') }}</span>
                    @endif
                    <div class="hint" title="{{ $message->created_at->format('Y-m-d H:i') }}">{{ $message->created_at->diffForHumans() }}@if ($staffView && $message->author) · {{ $message->author->email }}@endif</div>
                </div>
            </header>
            <div class="ticket-body">{!! nl2br(e($message->body)) !!}</div>
            @if ($message->attachments->isNotEmpty())
                <ul class="ticket-files">
                    @foreach ($message->attachments as $file)
                        <li><a href="{{ route('panel.tickets.attachment', $file) }}" target="_blank" rel="noopener"><x-icon name="paperclip" :size="14"/> {{ $file->original_name }}</a>
                            <span class="hint">{{ number_format($file->size / 1024, 0, ',', ' ') }} KB</span></li>
                    @endforeach
                </ul>
            @endif
        </article>
    @endforeach
</div>
