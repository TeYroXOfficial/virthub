@php $selected = old('events', $webhook?->events ?? ['*']); @endphp
<div class="grid grid-2">
    <div class="field"><label for="wh-name{{ $suffix }}">{{ __('Nazwa') }}</label>
        <input id="wh-name{{ $suffix }}" name="name" required maxlength="100" value="{{ old('name', $webhook?->name) }}" placeholder="{{ __('np. WHMCS, Slack, własny system') }}"></div>
    <div class="field"><label for="wh-url{{ $suffix }}">{{ __('Adres URL (POST)') }}</label>
        <input id="wh-url{{ $suffix }}" name="url" type="url" required maxlength="2000" value="{{ old('url', $webhook?->url) }}" placeholder="https://example.com/hooks/virthub"></div>
</div>
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
