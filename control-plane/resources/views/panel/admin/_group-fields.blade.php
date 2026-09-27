<div class="grid grid-2">
    <div class="field">
        <label for="group-name-{{ $idp }}">{{ __('Nazwa') }}</label>
        <input id="group-name-{{ $idp }}" name="name" type="text" value="{{ $group?->name }}" placeholder="{{ __('Warszawa DC1') }}" required>
    </div>
    <div class="field">
        <label for="group-description-{{ $idp }}">{{ __('Opis') }} <span class="muted">{{ __('(dla personelu)') }}</span></label>
        <input id="group-description-{{ $idp }}" name="description" type="text" value="{{ $group?->description }}" placeholder="{{ __('VLAN 120, wspólna /24') }}">
    </div>
    <div class="field">
        <label for="group-location-{{ $idp }}">{{ __('Lokalizacja') }} <span class="muted">{{ __('(dla klientów)') }}</span></label>
        <input id="group-location-{{ $idp }}" name="location" type="text" value="{{ $f['location'] }}" placeholder="{{ __('Warszawa, Polska') }}">
        <div class="hint">{{ __('Tak klient zobaczy tę grupę przy zamówieniu. Puste = nazwa grupy.') }}</div>
    </div>
    <div class="field">
        <label for="group-sort-{{ $idp }}">{{ __('Kolejność') }}</label>
        <input id="group-sort-{{ $idp }}" name="sort_order" type="text" inputmode="numeric" value="{{ $f['sort'] }}">
        <div class="hint">{{ __('Mniejsza liczba — wyżej na liście.') }}</div>
    </div>
</div>
<div class="field">
    <label class="check-line">
        <input type="checkbox" name="is_public" value="1" @checked($f['is_public'])>
        {{ __('Klient może wybrać tę lokalizację przy zamówieniu') }}
    </label>
    <input type="hidden" name="accepts_new_servers" value="0">
    <label class="check-line">
        <input type="checkbox" name="accepts_new_servers" value="1" @checked($f['accepts'])>
        {{ __('Węzły grupy przyjmują nowe maszyny') }}
    </label>
</div>
