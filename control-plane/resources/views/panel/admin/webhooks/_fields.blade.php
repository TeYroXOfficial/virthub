@php $selected = old('events', $webhook?->events ?? ['*']); @endphp
<div class="grid grid-2">
    <div class="field"><label for="wh-name{{ $suffix }}">{{ __('Nazwa') }}</label>
        <input id="wh-name{{ $suffix }}" name="name" required maxlength="100" value="{{ old('name', $webhook?->name) }}" placeholder="{{ __('np. WHMCS, Slack, własny system') }}"></div>
    <div class="field"><label for="wh-url{{ $suffix }}">{{ __('Adres URL (POST)') }}</label>
        <input id="wh-url{{ $suffix }}" name="url" type="url" required maxlength="2000" value="{{ old('url', $webhook?->url) }}" placeholder="https://example.com/hooks/virthub"></div>
</div>
@php $format = old('format', $webhook?->format ?? 'virthub'); $opts = (array) $webhook?->options; @endphp
<div class="grid grid-3">
    <div class="field"><label for="wh-format{{ $suffix }}">{{ __('Odbiornik') }}</label>
        <select id="wh-format{{ $suffix }}" name="format">
            @foreach (\App\Domain\Webhooks\WebhookFormats::FORMATS as $f)
                <option value="{{ $f }}" @selected($format === $f)>{{ \App\Domain\Webhooks\WebhookFormats::label($f) }}</option>
            @endforeach
        </select>
        <div class="hint">{{ __('Slack, Discord, Google Chat: adres „incoming webhook”. Telegram: https://api.telegram.org/bot<TOKEN>/sendMessage i ID czatu. Pushover: https://api.pushover.net/1/messages.json, klucz użytkownika i token aplikacji.') }}</div></div>
    <div class="field"><label for="wh-bearer{{ $suffix }}">{{ __('Token Bearer') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
        <input id="wh-bearer{{ $suffix }}" name="bearer_token" type="password" autocomplete="new-password" maxlength="500" placeholder="{{ $webhook?->bearer_token ? __('bez zmiany') : '' }}">
        <div class="hint">{{ __('Wysyłany jako Authorization: Bearer — dla odbiorców, którzy nie sprawdzają podpisu HMAC.') }}</div>
        @if ($webhook?->bearer_token) <label class="check-line"><input type="checkbox" name="clear_bearer" value="1"> {{ __('Usuń token') }}</label> @endif</div>
    <div class="field"><label for="wh-chat{{ $suffix }}">{{ __('Telegram: ID czatu') }}</label>
        <input id="wh-chat{{ $suffix }}" name="options[chat_id]" value="{{ old('options.chat_id', $opts['chat_id'] ?? '') }}" placeholder="-1001234567890 / @kanal"></div>
    <div class="field"><label for="wh-pu{{ $suffix }}">{{ __('Pushover: klucz użytkownika') }}</label>
        <input id="wh-pu{{ $suffix }}" name="options[user_key]" value="{{ old('options.user_key', $opts['user_key'] ?? '') }}" autocomplete="off"></div>
    <div class="field"><label for="wh-pt{{ $suffix }}">{{ __('Pushover: token aplikacji') }}</label>
        <input id="wh-pt{{ $suffix }}" name="options[app_token]" type="password" autocomplete="new-password" placeholder="{{ ($opts['app_token'] ?? null) ? __('bez zmiany') : '' }}"></div>
</div>
<p class="hint">{{ __('Własny odbiornik: przykłady weryfikacji podpisu (PHP, Node.js, Python) w docs/webhooks.md.') }}</p>
<fieldset class="field" style="border:0; padding:0">
    <legend style="font-weight:600; margin-bottom:6px">{{ __('Zdarzenia') }}</legend>
    <label style="font-weight:400; display:flex; gap:8px; align-items:center; margin-bottom:6px">
        <input type="checkbox" name="events[]" value="*" style="width:auto" @checked(in_array('*', $selected, true))>
        <strong>{{ __('Wszystkie zdarzenia (także dodane w przyszłych wersjach)') }}</strong>
    </label>
    <div class="grid grid-2" style="gap:2px 16px">
        @foreach ($events as $event => $label)
            <label style="font-weight:400; display:flex; gap:8px; align-items:center">
                <input type="checkbox" name="events[]" value="{{ $event }}" style="width:auto" @checked(in_array($event, $selected, true))>
                <span><code>{{ $event }}</code> <span class="muted">— {{ $label }}</span></span>
            </label>
        @endforeach
    </div>
</fieldset>
