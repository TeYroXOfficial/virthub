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
        <label class="check-line"><input type="checkbox" name="is_active" value="1" @checked($p?->is_active ?? true)> {{ __('Aktywny') }}</label>
    </div>
    <div class="field"><label>{{ __('Rodzaj kopii') }}</label>
        <select name="mode">
            @php $mode = $p?->backupMode() ?? 'full'; @endphp
            <option value="full" @selected($mode === 'full')>{{ __('pełne') }}</option>
            <option value="incremental" @selected($mode === 'incremental')>{{ __('przyrostowe (od poprzedniej kopii)') }}</option>
            <option value="differential" @selected($mode === 'differential')>{{ __('różnicowe (od ostatniej pełnej)') }}</option>
        </select>
        <div class="hint">{{ __('Przyrostowe są najmniejsze, ale przywracanie składa cały łańcuch; różnicowe rosną z czasem, a przywracanie potrzebuje tylko pełnej i jednej różnicowej.') }}</div></div>
    <div class="field"><label>{{ __('Pełna kopia co') }}</label><input name="full_every" type="number" min="1" max="60" required value="{{ $p?->full_every ?? 7 }}">
        <div class="hint">{{ __('Przy kopiach przyrostowych i różnicowych: co tyle kopii jedna pełna, pozostałe zapisują tylko zmienione bloki dysku (działająca maszyna KVM).') }}</div></div>
    <div class="field"><label>{{ __('Retencja GFS') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
        <div style="display:flex;gap:6px">
            <input name="keep_daily" type="number" min="0" max="365" value="{{ $p?->keep_daily }}" placeholder="{{ __('dni') }}" aria-label="{{ __('Dzienne') }}" style="max-width:80px">
            <input name="keep_weekly" type="number" min="0" max="260" value="{{ $p?->keep_weekly }}" placeholder="{{ __('tyg.') }}" aria-label="{{ __('Tygodniowe') }}" style="max-width:80px">
            <input name="keep_monthly" type="number" min="0" max="120" value="{{ $p?->keep_monthly }}" placeholder="{{ __('mies.') }}" aria-label="{{ __('Miesięczne') }}" style="max-width:80px">
        </div>
        <div class="hint">{{ __('Oprócz ostatnich N kopii zostaje najnowsza kopia z każdego z ostatnich D dni, W tygodni i M miesięcy.') }}</div></div>
    <div class="field" style="grid-column:1/-1"><label>{{ __('Dni tygodnia') }}</label>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            @foreach ([1 => __('pon'), 2 => __('wt'), 3 => __('śr'), 4 => __('czw'), 5 => __('pt'), 6 => __('sob'), 0 => __('niedz')] as $day => $label)
                <label class="check-line"><input type="checkbox" name="days[]" value="{{ $day }}" @checked(empty($p?->days) || in_array($day, (array) $p->days, true))> {{ $label }}</label>
            @endforeach
        </div></div>
</div>
