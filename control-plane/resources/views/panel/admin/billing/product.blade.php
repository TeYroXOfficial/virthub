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
                        @foreach ($categories as $c) <option value="{{ $c->id }}" @selected(old('product_category_id', $product->product_category_id) == $c->id)>{{ $c->name }}</option> @endforeach
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
                    <h3 class="card-title">{{ __('Ceny') }}</h3>
                    <p class="hint" style="margin-top:0">{{ __('Puste pole = cykl niedostępny. Godzinowe i dzienne są pobierane z portfela, pozostałe — fakturą.') }} {{ \App\Domain\Billing\Billing::pricesIncludeTax() ? __('Ceny brutto.') : __('Ceny netto (VAT doliczany).') }}</p>
                    @error('prices') <div class="alert alert-error">{{ $message }}</div> @enderror
                    <div class="grid grid-2">
                        @foreach (Cycle::ALL as $cycle)
                            <div class="field">
                                <label for="p-{{ $cycle }}">{{ ucfirst(Cycle::label($cycle)) }}</label>
                                <input id="p-{{ $cycle }}" name="prices[{{ $cycle }}]" inputmode="decimal" placeholder="—"
                                       value="{{ old('prices.'.$cycle, isset($prices[$cycle]) ? Money::input($prices[$cycle]) : '') }}">
                                @error('prices.'.$cycle) <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                    <div class="grid grid-2">
                        <div class="field"><label for="p-setup">{{ __('Opłata instalacyjna') }}</label><input id="p-setup" name="setup_fee" inputmode="decimal" value="{{ old('setup_fee', $product->setup_fee ? Money::input($product->setup_fee) : '') }}" placeholder="0"></div>
                        <div class="field"><label for="p-stock">{{ __('Limit sztuk') }}</label><input id="p-stock" type="number" name="stock" min="0" value="{{ old('stock', $product->stock) }}" placeholder="{{ __('bez limitu') }}"></div>
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
        })();
    </script>
@endpush
