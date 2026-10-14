@php $p = $plan; @endphp
<div class="grid grid-3">
    <div class="field"><label>{{ __('Nazwa') }}</label><input name="name" required maxlength="100" value="{{ $p?->name }}" placeholder="{{ __('np. Codziennie, 7 kopii') }}"></div>
    <div class="field"><label>{{ __('Cel') }}</label><select name="backup_target_id" required>
        @foreach ($targets as $target) <option value="{{ $target->id }}" @selected($p?->backup_target_id === $target->id)>{{ $target->name }} ({{ $target->type }})</option> @endforeach
    </select></div>
    <div class="field"><label>{{ __('Co ile godzin') }}</label><input name="interval_hours" type="number" min="1" max="720" required value="{{ $p?->interval_hours ?? 24 }}">
        <div class="hint">{{ __('24 = codziennie, 168 = co tydzień') }}</div></div>
    <div class="field"><label>{{ __('Godzina startu (UTC)') }}</label><input name="hour" type="number" min="0" max="23" required value="{{ $p?->hour ?? 3 }}">
        <div class="hint">{{ __('Dla planów co 24 h i rzadziej.') }}</div></div>
    <div class="field"><label>{{ __('Przechowywane kopie') }}</label><input name="retention" type="number" min="1" max="365" required value="{{ $p?->retention ?? 7 }}"></div>
    <div class="field"><label>{{ __('Opcje') }}</label>
        <label class="check-line"><input type="checkbox" name="compress" value="1" @checked($p?->compress ?? true)> {{ __('Kompresja') }}</label>
        <label class="check-line"><input type="checkbox" name="is_active" value="1" @checked($p?->is_active ?? true)> {{ __('Aktywny') }}</label></div>
</div>
