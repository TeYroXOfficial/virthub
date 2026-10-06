@extends('layouts.panel')

@php
    $lang = \App\Domain\Mail\EmailTemplates::baseOf(app()->getLocale());
    $mailLocales = collect(app(\App\Domain\Settings\Languages::class)->all())->pluck('name', 'code')->all() ?: ['pl' => 'Polski', 'en' => 'English'];
    // Znaczniki zmiennych składamy w PHP — w szablonie Blade „{{” w tekście myli parser.
    $token = fn (string $var) => '{'.'{ '.$var.' }'.'}';
@endphp

@section('title', $definition['name'][$lang])

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.emails') }}" style="font-size:13px">{{ __('← Szablony e-mail') }}</a>
            <h1>{{ $definition['name'][$lang] }}</h1>
            <div class="meta-line">
                <span class="mono">{{ $key }}</span>
                <span class="sep">·</span>
                @foreach ($mailLocales as $loc => $label)
                    <a href="{{ route('panel.admin.emails.edit', [$key, $loc]) }}" class="pill {{ $loc === $locale ? 'info' : 'neutral' }} plain">{{ $label }}</a>
                @endforeach
                @if ($template['custom']) <span class="pill info">{{ __('zmieniony') }}</span> @endif
            </div>
        </div>
        <div class="actions">
            <form method="POST" action="{{ route('panel.admin.emails.test', [$key, $locale]) }}" style="margin:0">
                @csrf
                <button class="btn" type="submit"><x-icon name="mail" :size="15"/> {{ __('Wyślij test do mnie') }}</button>
            </form>
            @if ($template['custom'])
                <form method="POST" action="{{ route('panel.admin.emails.reset', [$key, $locale]) }}" style="margin:0"
                      data-confirm="{{ __('Przywrócić domyślną treść? Twoje zmiany przepadną.') }}">
                    @csrf @method('DELETE')
                    <button class="btn" type="submit">{{ __('Przywróć domyślny') }}</button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <form method="POST" action="{{ route('panel.admin.emails.update', [$key, $locale]) }}">
                @csrf @method('PUT')
                <label class="check-line" style="margin-bottom:12px">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $template['enabled']))>
                    {{ __('Wysyłaj tę wiadomość') }}
                </label>
                <div class="field">
                    <label for="t-subject">{{ __('Temat') }}</label>
                    <input id="t-subject" name="subject" type="text" maxlength="255" required value="{{ old('subject', $template['subject']) }}">
                </div>
                <div class="field">
                    <label for="t-body">{{ __('Treść (Markdown)') }}</label>
                    <textarea id="t-body" name="body" rows="16" class="mono" required>{{ old('body', $template['body']) }}</textarea>
                    <div class="hint">{{ __('**pogrubienie**, *kursywa*, [tekst linku](adres), listy zaczynane od „- ”. HTML nie jest dozwolony.') }}</div>
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
        </div>

        <div>
            <div class="card">
                <h3 class="card-title">{{ __('Zmienne') }}</h3>
                <p class="hint" style="margin-top:0">{{ __('Wstaw je w temat lub treść — panel podstawi wartości przy wysyłce.') }}</p>
                <div class="var-list">
                    @foreach ($variables as $var)
                        <code class="copyable" data-copy="{{ $token($var) }}" title="{{ __('Kliknij, żeby skopiować') }}">{{ $token($var) }}</code>
                    @endforeach
                </div>
            </div>
            <div class="card" style="margin-top:16px">
                <h3 class="card-title">{{ __('Podgląd (przykładowe dane)') }}</h3>
                <p><strong>{{ $previewSubject }}</strong></p>
                <iframe class="mail-preview" sandbox srcdoc="{{ $previewHtml }}" title="{{ __('Podgląd') }}"></iframe>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('.var-list [data-copy]').forEach((el) => el.addEventListener('click', () => {
            navigator.clipboard?.writeText(el.dataset.copy);
            const body = document.getElementById('t-body');
            if (body && document.activeElement !== body) { body.focus(); }
        }));
    </script>
@endpush
