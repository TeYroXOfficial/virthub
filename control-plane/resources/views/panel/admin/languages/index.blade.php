@extends('layouts.panel')

@section('title', __('Języki'))

@section('content')
    <div class="page-header"><div><h1>{{ __('Języki') }}</h1>
        <div class="meta-line"><span>{{ __('Domyślny język obowiązuje dla gości i kont bez wybranego języka. Klienci wybierają spośród włączonych.') }}</span></div></div></div>
    @error('language') <div class="alert alert-error">{{ $message }}</div> @enderror

    <div class="card flush dash-section">
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Język') }}</th><th>{{ __('Kod') }}</th><th>{{ __('Przetłumaczone') }}</th><th class="num">{{ __('Konta') }}</th><th>{{ __('Domyślny') }}</th><th>{{ __('Włączony') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($languages as $language)
                    @php $p = $progress[$language->code]; $pct = $p['total'] ? (int) floor($p['done'] * 100 / $p['total']) : 0; @endphp
                    <tr>
                        <td><strong>{{ $language->name }}</strong>
                            @if ($language->isBuiltin()) <span class="pill neutral plain">{{ __('wbudowany') }}</span>
                            @else <div class="hint">{{ __('brakujące teksty z: :base', ['base' => strtoupper($language->base)]) }}</div> @endif</td>
                        <td class="mono">{{ $language->code }}</td>
                        <td style="min-width:160px">
                            <div class="meter"><i style="width:{{ $pct }}%"></i></div>
                            <span class="hint">{{ $language->code === \App\Domain\Settings\Languages::SOURCE ? __('język źródłowy') : __(':done z :total (:pct%)', ['done' => $p['done'], 'total' => $p['total'], 'pct' => $pct]) }}</span>
                        </td>
                        <td class="num">{{ $users[$language->code] ?? 0 }}</td>
                        <td>
                            @if ($default === $language->code)
                                <span class="pill ok">{{ __('domyślny') }}</span>
                            @else
                                <form method="POST" action="{{ route('panel.admin.languages.default') }}" style="margin:0">
                                    @csrf <input type="hidden" name="code" value="{{ $language->code }}">
                                    <button class="btn btn-sm" type="submit">{{ __('Ustaw jako domyślny') }}</button>
                                </form>
                            @endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('panel.admin.languages.update', $language) }}" style="margin:0">
                                @csrf @method('PUT')
                                <input type="hidden" name="name" value="{{ $language->name }}">
                                <input type="hidden" name="is_enabled" value="{{ $language->is_enabled ? 0 : 1 }}">
                                <button class="btn btn-sm {{ $language->is_enabled ? '' : 'btn-primary' }}" type="submit" @disabled($default === $language->code)>
                                    {{ $language->is_enabled ? __('Wyłącz') : __('Włącz') }}</button>
                            </form>
                        </td>
                        <td style="text-align:right; white-space:nowrap">
                            <a class="btn btn-sm" href="{{ route('panel.admin.languages.translations', $language) }}">{{ __('Tłumaczenia') }}</a>
                            <button class="btn btn-sm btn-ghost" type="button" onclick="document.getElementById('lang-edit-{{ $language->id }}').showModal()">{{ __('Edytuj') }}</button>
                            <dialog class="modal edit-modal" id="lang-edit-{{ $language->id }}">
                                <form method="POST" action="{{ route('panel.admin.languages.update', $language) }}">
                                    @csrf @method('PUT')
                                    <h3 class="card-title">{{ __('Edytuj język :name', ['name' => $language->name]) }}</h3>
                                    <div class="field"><label>{{ __('Nazwa (w tym języku)') }}</label><input name="name" required maxlength="60" value="{{ $language->name }}"></div>
                                    @unless ($language->isBuiltin())
                                        <div class="field"><label>{{ __('Brakujące teksty z języka') }}</label>
                                            <select name="base">
                                                @foreach (\App\Domain\Settings\Languages::BUILTIN as $b) <option value="{{ $b }}" @selected($language->base === $b)>{{ strtoupper($b) }}</option> @endforeach
                                            </select></div>
                                    @endunless
                                    <div class="field"><label>{{ __('Kolejność') }}</label><input name="sort_order" type="number" min="0" max="1000" value="{{ $language->sort_order }}"></div>
                                    <input type="hidden" name="is_enabled" value="{{ $language->is_enabled ? 1 : 0 }}">
                                    <div class="btn-row" style="justify-content:flex-end">
                                        <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('Anuluj') }}</button>
                                        <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
                                    </div>
                                </form>
                            </dialog>
                            @unless ($language->isBuiltin() || $default === $language->code)
                                <form method="POST" action="{{ route('panel.admin.languages.destroy', $language) }}" style="display:inline; margin:0"
                                      data-confirm="{{ __('Usunąć język :name razem z tłumaczeniami? Konta z tym językiem przejdą na domyślny.', ['name' => $language->name]) }}">
                                    @csrf @method('DELETE') <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Usuń') }}"><x-icon name="trash" :size="14"/></button></form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <form method="POST" action="{{ route('panel.admin.languages.default') }}" class="filter-bar" style="margin:0">
            @csrf
            <input type="hidden" name="code" value="{{ $default }}">
            <input type="hidden" name="detect_browser" value="0">
            <label class="check-line" style="margin:0"><input type="checkbox" name="detect_browser" value="1" @checked($detectBrowser)>
                {{ __('Wykrywaj język przeglądarki gościa (gdy jest włączony w panelu)') }}</label>
            <button class="btn btn-sm" type="submit">{{ __('Zapisz') }}</button>
        </form>
        <p class="hint">{{ __('Wyłączone — goście zawsze widzą język domyślny. Wybór zapisany na koncie klienta zawsze ma pierwszeństwo.') }}</p>
    </div>

    <div class="card" style="margin-top:16px">
        <h3 class="card-title"><x-icon name="plus" :size="16"/> {{ __('Dodaj język') }}</h3>
        <form method="POST" action="{{ route('panel.admin.languages.store') }}" class="filter-bar">
            @csrf
            <div class="field" style="margin:0"><label for="l-code">{{ __('Kod (ISO)') }}</label>
                <input id="l-code" name="code" required maxlength="12" placeholder="de" value="{{ old('code') }}" style="max-width:110px"></div>
            <div class="field" style="margin:0"><label for="l-name">{{ __('Nazwa (w tym języku)') }}</label>
                <input id="l-name" name="name" required maxlength="60" placeholder="Deutsch" value="{{ old('name') }}"></div>
            <div class="field" style="margin:0"><label for="l-base">{{ __('Brakujące teksty z języka') }}</label>
                <select id="l-base" name="base">
                    @foreach (\App\Domain\Settings\Languages::BUILTIN as $b) <option value="{{ $b }}" @selected(old('base', 'en') === $b)>{{ strtoupper($b) }}</option> @endforeach
                </select></div>
            <button class="btn btn-primary" type="submit">{{ __('Dodaj') }}</button>
        </form>
        @error('code') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
        <p class="hint">{{ __('Nowy język jest wyłączony, dopóki go nie włączysz. Teksty bez tłumaczenia pokazują się w języku bazowym. Tłumaczenia możesz wpisać w panelu albo wgrać plik JSON (np. przetłumaczony eksport angielskiego).') }}</p>
    </div>
@endsection
