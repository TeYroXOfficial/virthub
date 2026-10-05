@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Domain\Billing\Cycle')

@section('title', $product->exists ? $product->name : __('Nowy produkt'))

@php
    $type = old('type', $product->type);
    $eggIds = array_map('intval', old('app_egg_ids', $product->app_egg_ids ?? []));
    $groupIds = array_map('intval', old('hypervisor_group_ids', $product->hypervisor_group_ids ?? []));
@endphp

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.billing.catalog') }}" style="font-size:13px">{{ __('← Produkty') }}</a>
            <h1>{{ $product->exists ? $product->name : __('Nowy produkt') }}</h1>
        </div>
    </div>

    @error('product') <div class="alert alert-error">{{ $message }}</div> @enderror

    @if ($categories->isEmpty())
        <div class="alert alert-warning">{{ __('Najpierw dodaj kategorię.') }} <a href="{{ route('panel.admin.billing.catalog') }}">{{ __('Produkty') }}</a></div>
    @else
    <form method="POST" action="{{ $product->exists ? route('panel.admin.billing.products.update', $product) : route('panel.admin.billing.products.store') }}">
        @csrf @if ($product->exists) @method('PUT') @endif
        <div class="grid grid-2">
            <div class="card">
                <h3 class="card-title">{{ __('Produkt') }}</h3>
                <div class="field"><label for="p-name">{{ __('Nazwa') }}</label><input id="p-name" name="name" required maxlength="100" value="{{ old('name', $product->name) }}"></div>
                <div class="field"><label for="p-cat">{{ __('Kategoria') }}</label>
                    <select id="p-cat" name="product_category_id" required>
                        @foreach ($categories as $c) <option value="{{ $c->id }}" data-cycles="{{ implode(',', $c->allowed_cycles ?? []) }}" @selected(old('product_category_id', $product->product_category_id) == $c->id)>{{ $c->name }}</option> @endforeach
                    </select></div>
                <div class="field"><label for="p-desc">{{ __('Opis') }}</label><textarea id="p-desc" name="description" rows="3" class="prose-input" maxlength="2000">{{ old('description', $product->description) }}</textarea></div>
                <div class="field"><label>{{ __('Rodzaj') }}</label>
                    <div class="btn-row">
                        <label class="check-line" style="margin:0"><input type="radio" name="type" value="vps" @checked($type === 'vps')> {{ __('Serwer VPS') }}</label>
                        <label class="check-line" style="margin:0"><input type="radio" name="type" value="app" @checked($type === 'app')> {{ __('Aplikacja') }}</label>
                    </div></div>
                <div class="field" data-type="vps"><label for="p-pkg">{{ __('Pakiet VPS') }}</label>
                    <select id="p-pkg" name="vps_package_id">
                        <option value="">—</option>
                        @foreach ($packages as $p) <option value="{{ $p->id }}" @selected(old('vps_package_id', $product->vps_package_id) == $p->id)>{{ $p->name }} — {{ $p->vcpu }} vCPU, {{ round($p->ram_mb / 1024, 1) }} GB RAM, {{ $p->disk_gb }} GB @unless ($p->is_active) ({{ __('nieaktywny') }}) @endunless</option> @endforeach
                    </select>
                    @error('vps_package_id') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror</div>
                <div class="field" data-type="app"><label for="p-plan">{{ __('Plan aplikacji') }}</label>
                    <select id="p-plan" name="app_plan_id">
                        <option value="">—</option>
                        @foreach ($plans as $p) <option value="{{ $p->id }}" @selected(old('app_plan_id', $product->app_plan_id) == $p->id)>{{ $p->name }} — {{ round($p->memory_mb / 1024, 1) }} GB RAM, {{ round($p->disk_mb / 1024, 1) }} GB</option> @endforeach
                    </select>
                    @error('app_plan_id') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror</div>
                <div class="field" data-type="app"><label>{{ __('Dozwolone szablony aplikacji') }}</label>
                    <div class="hint">{{ __('Nic nie zaznaczone = wszystkie aktywne.') }}</div>
                    <div class="check-columns">
                        @foreach ($eggs as $egg) <label class="check-line"><input type="checkbox" name="app_egg_ids[]" value="{{ $egg->id }}" @checked(in_array($egg->id, $eggIds, true))> {{ $egg->displayName() }}</label> @endforeach
                    </div></div>
            </div>

            <div>
                <div class="card">
                    <h3 class="card-title">{{ __('Okresy i ceny') }}</h3>
                    <p class="hint" style="margin-top:0">{{ __('Ustal, na jak długo klient kupuje usługę: liczba i jednostka (godziny, dni, tygodnie, miesiące, lata). Puste pole ceny albo 0 = za darmo.') }}
                        {{ __('Odnawiane godziny i dni są pobierane z portfela za każdy rozpoczęty okres, tygodnie, miesiące i lata — fakturą. „Jednorazowo” = płatność z góry za cały okres, potem usługa się kończy.') }}
                        {{ \App\Domain\Billing\Billing::pricesIncludeTax() ? __('Ceny brutto.') : __('Ceny netto (VAT doliczany).') }}</p>
                    @error('prices') <div class="alert alert-error">{{ $message }}</div> @enderror
                    @foreach ($errors->getMessages() as $key => $msgs)
                        @if (str_starts_with($key, 'periods.')) <div class="alert alert-error">{{ $msgs[0] }}</div> @endif
                    @endforeach
                    <input type="hidden" name="period_rows" value="1">
                    @php
                        $rows = old('period_rows')
                            ? array_values(old('periods', []))
                            : collect($prices)->map(function ($amount, $code) use ($product) {
                                [$count, $unit] = Cycle::parse($code);
                                return ['count' => $count, 'unit' => $unit, 'price' => $amount > 0 ? Money::input($amount) : '', 'once' => ! $product->renews($code)];
                            })->values()->all();
                        if (! $product->exists && ! old('period_rows')) {
                            $rows = [['count' => 1, 'unit' => 'm', 'price' => '', 'once' => false]];
                        }
                        $units = Cycle::unitLabels();
                    @endphp
                    <div class="period-list" id="period-list">
                        @foreach ($rows as $i => $row)
                            <div class="period-row" data-unit="{{ $row['unit'] }}">
                                <input type="number" name="periods[{{ $i }}][count]" min="1" max="720" required value="{{ $row['count'] }}" aria-label="{{ __('Długość') }}">
                                <select name="periods[{{ $i }}][unit]" aria-label="{{ __('Jednostka') }}">
                                    @foreach ($units as $u => $label) <option value="{{ $u }}" @selected($row['unit'] === $u)>{{ $label }}</option> @endforeach
                                </select>
                                <input name="periods[{{ $i }}][price]" inputmode="decimal" placeholder="{{ __('za darmo') }}" value="{{ $row['price'] }}" aria-label="{{ __('Cena') }}">
                                <label class="check-line" style="margin:0"><input type="checkbox" name="periods[{{ $i }}][once]" value="1" @checked(! empty($row['once']))> {{ __('jednorazowo') }}</label>
                                <button class="btn btn-sm btn-ghost" type="button" data-remove aria-label="{{ __('Usuń okres') }}"><x-icon name="trash" :size="14"/></button>
                                <span class="hint cycle-blocked" hidden>{{ __('kategoria nie dopuszcza') }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="btn-row" style="margin:6px 0 16px; flex-wrap:wrap">
                        <button class="btn btn-sm" type="button" data-add-period data-count="1" data-unit="m"><x-icon name="plus" :size="14"/> {{ __('Dodaj okres') }}</button>
                        <span class="hint" style="margin:0">{{ __('Szybko:') }}</span>
                        @foreach ([[1, 'h'], [1, 'd'], [7, 'd'], [1, 'w'], [1, 'm'], [3, 'm'], [6, 'm'], [12, 'm']] as [$c, $u])
                            <button class="btn btn-sm btn-ghost" type="button" data-add-period data-count="{{ $c }}" data-unit="{{ $u }}">{{ Cycle::duration(Cycle::code($c, $u)) }}</button>
                        @endforeach
                    </div>
                    <template id="period-template">
                        <div class="period-row" data-unit="m">
                            <input type="number" data-name="count" min="1" max="720" required value="1" aria-label="{{ __('Długość') }}">
                            <select data-name="unit" aria-label="{{ __('Jednostka') }}">
                                @foreach ($units as $u => $label) <option value="{{ $u }}">{{ $label }}</option> @endforeach
                            </select>
                            <input data-name="price" inputmode="decimal" placeholder="{{ __('za darmo') }}" aria-label="{{ __('Cena') }}">
                            <label class="check-line" style="margin:0"><input type="checkbox" data-name="once" value="1"> {{ __('jednorazowo') }}</label>
                            <button class="btn btn-sm btn-ghost" type="button" data-remove aria-label="{{ __('Usuń okres') }}"><x-icon name="trash" :size="14"/></button>
                            <span class="hint cycle-blocked" hidden>{{ __('kategoria nie dopuszcza') }}</span>
                        </div>
                    </template>
                    <div class="grid grid-2">
                        <div class="field"><label for="p-setup">{{ __('Opłata instalacyjna') }}</label><input id="p-setup" name="setup_fee" inputmode="decimal" value="{{ old('setup_fee', $product->setup_fee ? Money::input($product->setup_fee) : '') }}" placeholder="0"></div>
                        <div class="field"><label for="p-stock">{{ __('Limit sztuk') }}</label><input id="p-stock" type="number" name="stock" min="0" value="{{ old('stock', $product->stock) }}" placeholder="{{ __('bez limitu') }}"></div>
                        <div class="field"><label for="p-peruser">{{ __('Limit na klienta') }}</label><input id="p-peruser" type="number" name="per_user_limit" min="1" value="{{ old('per_user_limit', $product->per_user_limit) }}" placeholder="{{ __('bez limitu') }}">
                            <div class="hint">{{ __('Np. 1 dla darmowego produktu — jeden na konto.') }}</div></div>
                    </div>
                </div>
                @php
                    [$kaCount, $kaUnit] = $product->keepalive_interval ? Cycle::parse($product->keepalive_interval) : [1, 'd'];
                    [$kwCount, $kwUnit] = $product->keepalive_window ? Cycle::parse($product->keepalive_window) : [12, 'h'];
                    $kaOn = old('keepalive', $product->keepalive_interval ? 1 : 0);
                @endphp
                <div class="card" style="margin-top:16px">
                    <h3 class="card-title">{{ __('Potwierdzanie aktywności') }}</h3>
                    <label class="check-line"><input type="checkbox" name="keepalive" value="1" @checked($kaOn) data-keepalive-toggle> {{ __('Klient musi co jakiś czas kliknąć „Przedłuż”') }}</label>
                    <p class="hint">{{ __('Np. dla usług za darmo: bez kliknięcia na czas usługa jest zawieszana, a po dniach ustawionych w billingu usuwana. Kliknięcie przywraca zawieszoną usługę.') }}</p>
                    <div class="grid grid-2" data-keepalive-fields>
                        <div class="field">
                            <label>{{ __('Ważność po kliknięciu') }}</label>
                            <div class="period-row" style="grid-template-columns: 80px minmax(0, 1fr)">
                                <input type="number" name="keepalive_count" min="1" max="720" value="{{ old('keepalive_count', $kaCount) }}" aria-label="{{ __('Długość') }}">
                                <select name="keepalive_unit" aria-label="{{ __('Jednostka') }}">
                                    @foreach (Cycle::unitLabels() as $u => $label) <option value="{{ $u }}" @selected(old('keepalive_unit', $kaUnit) === $u)>{{ $label }}</option> @endforeach
                                </select>
                            </div>
                            @error('keepalive_count') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                        </div>
                        <div class="field">
                            <label>{{ __('Przycisk aktywny na ostatnie') }}</label>
                            <div class="period-row" style="grid-template-columns: 80px minmax(0, 1fr)">
                                <input type="number" name="keepalive_window_count" min="1" max="720" value="{{ old('keepalive_window_count', $kwCount) }}" aria-label="{{ __('Długość') }}">
                                <select name="keepalive_window_unit" aria-label="{{ __('Jednostka') }}">
                                    @foreach (Cycle::unitLabels() as $u => $label) <option value="{{ $u }}" @selected(old('keepalive_window_unit', $kwUnit) === $u)>{{ $label }}</option> @endforeach
                                </select>
                            </div>
                            @error('keepalive_window_count') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                            <div class="hint">{{ __('Przed tym czasem przycisk jest zablokowany, a pasek odlicza do odblokowania.') }}</div>
                        </div>
                    </div>
                </div>
                <div class="card" style="margin-top:16px">
                    <h3 class="card-title">{{ __('Lokalizacje') }}</h3>
                    <div class="hint">{{ __('Nic nie zaznaczone = każda publiczna grupa hypervisorów. Zaznaczone = klient musi wybrać jedną z nich.') }}</div>
                    <div class="check-columns">
                        @foreach ($locations as $g) <label class="check-line"><input type="checkbox" name="hypervisor_group_ids[]" value="{{ $g->id }}" @checked(in_array($g->id, $groupIds, true))> {{ $g->publicName() }} @unless ($g->is_public) <span class="hint">({{ __('niepubliczna') }})</span> @endunless</label> @endforeach
                    </div>
                </div>
                <div class="card" style="margin-top:16px">
                    <div class="grid grid-2">
                        <div class="field" style="margin:0"><label for="p-sort">{{ __('Kolejność') }}</label><input id="p-sort" type="number" name="sort_order" min="0" max="10000" value="{{ old('sort_order', $product->sort_order ?? 0) }}"></div>
                        <label class="check-line" style="align-self:end"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active))> {{ __('W sprzedaży') }}</label>
                    </div>
                </div>
                @if ($product->exists && $liveCount > 0)
                    <div class="card" style="margin-top:16px">
                        <h3 class="card-title">{{ __('Istniejące usługi (:count)', ['count' => $liveCount]) }}</h3>
                        <p class="hint" style="margin-top:0">{{ __('Bez zaznaczenia zmiany dotyczą tylko nowych zamówień.') }}</p>
                        <label class="check-line"><input type="checkbox" name="apply_prices" value="1" @checked(old('apply_prices'))> {{ __('Zmień cenę istniejących usług') }}</label>
                        <p class="hint">{{ __('Nowa cena obowiązuje od najbliższej opłaty: kolejnej faktury odnowienia albo naliczenia godzinowego. Faktury już wystawione się nie zmieniają.') }}</p>
                        <label class="check-line"><input type="checkbox" name="apply_keepalive" value="1" @checked(old('apply_keepalive'))> {{ __('Zastosuj ustawienie potwierdzania aktywności do istniejących usług') }}</label>
                        <p class="hint">{{ __('Czas liczy się od chwili zapisu. Wyłączenie potwierdzania przywraca usługi zawieszone za jego brak.') }}</p>
                        <label class="check-line"><input type="checkbox" name="apply_resources" value="1" @checked(old('apply_resources'))>
                            {{ $product->type === 'app' ? __('Ustaw zasoby planu w istniejących aplikacjach') : __('Sprawdź, które maszyny mają inne parametry niż pakiet') }}</label>
                        <p class="hint">{{ $product->type === 'app'
                            ? __('RAM i procesor działają od razu, dysk po restarcie aplikacji.')
                            : __('Maszynę VPS zmienia się na jej stronie („Zmień pakiet”), bo wymaga to zatrzymania.') }}</p>
                    </div>
                @endif
            </div>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:16px">{{ __('Zapisz') }}</button>
    </form>
    @endif
@endsection

@push('scripts')
    <script>
        (function () {
            function sync() {
                var t = (document.querySelector('input[name=type]:checked') || {}).value;
                document.querySelectorAll('[data-type]').forEach(function (el) { el.hidden = el.dataset.type !== t; });
            }
            document.querySelectorAll('input[name=type]').forEach(function (r) { r.addEventListener('change', sync); });
            sync();

            var kaToggle = document.querySelector('[data-keepalive-toggle]');
            function syncKeepalive() { document.querySelector('[data-keepalive-fields]').hidden = !kaToggle.checked; }
            if (kaToggle) { kaToggle.addEventListener('change', syncKeepalive); syncKeepalive(); }

            // Wiersze okresów: dodawanie, usuwanie i oznaczanie jednostek, których kategoria nie dopuszcza.
            var list = document.getElementById('period-list');
            var tpl = document.getElementById('period-template');
            var cat = document.getElementById('p-cat');
            var next = list ? list.children.length : 0;
            function syncCycles() {
                var opt = cat && cat.options[cat.selectedIndex];
                var allowed = opt && opt.dataset.cycles ? opt.dataset.cycles.split(',') : null;
                list.querySelectorAll('.period-row').forEach(function (row) {
                    var unit = row.querySelector('select').value;
                    var blocked = allowed !== null && allowed.indexOf(unit) === -1;
                    row.classList.toggle('is-blocked', blocked);
                    row.querySelector('.cycle-blocked').hidden = !blocked;
                });
            }
            function addRow(count, unit) {
                var row = tpl.content.firstElementChild.cloneNode(true);
                row.querySelectorAll('[data-name]').forEach(function (el) {
                    el.name = 'periods[' + next + '][' + el.dataset.name + ']';
                });
                row.querySelector('[data-name=count]').value = count;
                row.querySelector('[data-name=unit]').value = unit;
                list.appendChild(row);
                next++;
                syncCycles();
                row.querySelector('[data-name=price]').focus();
            }
            if (list) {
                document.querySelectorAll('[data-add-period]').forEach(function (b) {
                    b.addEventListener('click', function () { addRow(b.dataset.count, b.dataset.unit); });
                });
                list.addEventListener('click', function (e) {
                    var btn = e.target.closest('[data-remove]');
                    if (btn) { btn.closest('.period-row').remove(); }
                });
                list.addEventListener('change', syncCycles);
                if (cat) cat.addEventListener('change', syncCycles);
                syncCycles();
            }
        })();
    </script>
@endpush
