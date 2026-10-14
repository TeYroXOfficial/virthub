@php $r = $rule; @endphp
<div class="grid grid-3">
    <div class="field"><label for="ar-name{{ $suffix }}">{{ __('Nazwa') }}</label>
        <input id="ar-name{{ $suffix }}" name="name" required maxlength="100" value="{{ $r?->name }}" placeholder="{{ __('np. CPU maszyny > 90%') }}"></div>
    <div class="field"><label for="ar-metric{{ $suffix }}">{{ __('Metryka') }}</label>
        <select id="ar-metric{{ $suffix }}" name="metric">
            @foreach ($metrics as $key => $m) <option value="{{ $key }}" @selected($r?->metric === $key)>{{ $m['label'] }}</option> @endforeach
        </select></div>
    <div class="field"><label for="ar-node{{ $suffix }}">{{ __('Zakres') }}</label>
        <select id="ar-node{{ $suffix }}" name="hypervisor_id">
            <option value="">{{ __('Wszystkie węzły') }}</option>
            @foreach ($hypervisors as $h) <option value="{{ $h->id }}" @selected($r?->hypervisor_id === $h->id)>{{ $h->name }}</option> @endforeach
        </select></div>
    <div class="field"><label for="ar-thr{{ $suffix }}">{{ __('Próg') }}</label>
        <input id="ar-thr{{ $suffix }}" name="threshold" type="number" min="0" max="100000" required value="{{ $r?->threshold ?? 90 }}">
        <div class="hint">{{ __('Wartość, powyżej której alert się otwiera (w jednostce metryki).') }}</div></div>
    <div class="field"><label for="ar-dur{{ $suffix }}">{{ __('Czas trwania (minuty)') }}</label>
        <input id="ar-dur{{ $suffix }}" name="duration_minutes" type="number" min="0" max="1440" required value="{{ $r?->duration_minutes ?? 5 }}">
        <div class="hint">{{ __('Średnia z tego okna (maszyny) albo jak długo próg musi być przekroczony. Brak kontaktu z węzłem: po tylu minutach.') }}</div></div>
    <div class="field">
        <label>{{ __('Powiadomienia') }}</label>
        <label class="check-line"><input type="checkbox" name="notify_email" value="1" @checked($r?->notify_email ?? true)> {{ __('E-mail do personelu (Hypervisory)') }}</label>
        <label class="check-line"><input type="checkbox" name="notify_webhook" value="1" @checked($r?->notify_webhook ?? true)> {{ __('Webhook (alert.triggered / alert.resolved)') }}</label>
        <label class="check-line"><input type="checkbox" name="is_active" value="1" @checked($r?->is_active ?? true)> {{ __('Aktywna') }}</label>
    </div>
</div>
