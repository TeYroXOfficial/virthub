<div class="grid grid-2">
    <div class="field">
        <label for="tg-name-{{ $idp }}">Nazwa systemu</label>
        <input id="tg-name-{{ $idp }}" name="name" type="text" value="{{ $group?->name }}" placeholder="Ubuntu" required>
    </div>
    <div class="field">
        <label for="tg-family-{{ $idp }}">Rodzina</label>
        <select id="tg-family-{{ $idp }}" name="family" required>
            @foreach (\App\Models\OsTemplateGroup::FAMILIES as $key => $label)
                <option value="{{ $key }}" @selected(($group?->family ?? 'ubuntu') === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <div class="hint">Decyduje o znaczku i kolorze.</div>
    </div>
    <div class="field">
        <label for="tg-desc-{{ $idp }}">Opis <span class="muted">(opcjonalnie)</span></label>
        <input id="tg-desc-{{ $idp }}" name="description" type="text" value="{{ $group?->description }}" placeholder="Popularna dystrybucja z długim wsparciem">
    </div>
    <div class="field">
        <label for="tg-sort-{{ $idp }}">Kolejność</label>
        <input id="tg-sort-{{ $idp }}" name="sort_order" type="text" inputmode="numeric" value="{{ $group?->sort_order ?? 0 }}">
    </div>
</div>
