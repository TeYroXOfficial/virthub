@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Domain\Billing\Cycle')

@section('title', __('Produkty'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Produkty') }}</h1>
            <p class="lede">{{ __('Kategorie sklepu i produkty: pakiet VPS albo plan aplikacji, ceny dla cykli, dozwolone lokalizacje.') }}</p>
        </div>
        <div class="actions"><a class="btn btn-primary" href="{{ route('panel.admin.billing.products.create') }}"><x-icon name="plus" :size="15"/> {{ __('Nowy produkt') }}</a></div>
    </div>
    @error('category') <div class="alert alert-error">{{ $message }}</div> @enderror
    @error('product') <div class="alert alert-error">{{ $message }}</div> @enderror

    @foreach ($categories as $category)
        <div class="card flush dash-section" style="margin-bottom:16px">
            <div class="dash-head">
                <form method="POST" action="{{ route('panel.admin.billing.categories.update', $category) }}" class="filter-bar" style="flex:1">
                    @csrf @method('PUT')
                    <input name="name" value="{{ $category->name }}" required maxlength="100" aria-label="{{ __('Nazwa') }}" style="max-width:220px; font-weight:600">
                    <input name="description" value="{{ $category->description }}" maxlength="500" placeholder="{{ __('Opis') }}" aria-label="{{ __('Opis') }}" style="flex:1; min-width:180px">
                    <input type="number" name="sort_order" value="{{ $category->sort_order }}" min="0" max="10000" aria-label="{{ __('Kolejność') }}" style="max-width:80px">
                    <label class="check-line" style="margin:0"><input type="checkbox" name="is_active" value="1" @checked($category->is_active)> {{ __('widoczna') }}</label>
                    <details class="cycle-pop">
                        <summary class="btn btn-sm">{{ __('Okresy') }}: {{ $category->allowed_cycles ? collect($category->allowed_cycles)->map(fn ($c) => Cycle::label($c))->join(', ') : __('wszystkie') }}</summary>
                        <div class="cycle-pop-body">
                            <p class="hint" style="margin-top:0">{{ __('Okresy, w których można kupić produkty tej kategorii. Nic nie zaznaczone = wszystkie.') }}</p>
                            @foreach (Cycle::ALL as $cycle)
                                <label class="check-line"><input type="checkbox" name="cycles[]" value="{{ $cycle }}" @checked(in_array($cycle, $category->allowed_cycles ?? [], true))> {{ ucfirst(Cycle::label($cycle)) }}</label>
                            @endforeach
                        </div>
                    </details>
                    <button class="btn btn-sm" type="submit">{{ __('Zapisz') }}</button>
                </form>
                <div class="btn-row">
                    <a class="btn btn-sm" href="{{ route('panel.admin.billing.products.create', ['category' => $category->id]) }}"><x-icon name="plus" :size="14"/> {{ __('Produkt') }}</a>
                    <form method="POST" action="{{ route('panel.admin.billing.categories.destroy', $category) }}" style="margin:0" data-confirm="{{ __('Usunąć kategorię :name?', ['name' => $category->name]) }}">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Usuń') }}"><x-icon name="trash" :size="14"/></button>
                    </form>
                </div>
            </div>
            @if ($category->products->isEmpty())
                <p class="empty-note">{{ __('Brak produktów w tej kategorii.') }}</p>
            @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Produkt') }}</th><th>{{ __('Zasoby') }}</th><th>{{ __('Ceny') }}</th><th>{{ __('Usługi') }}</th><th>{{ __('Stan') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($category->products as $product)
                            <tr>
                                <td><a href="{{ route('panel.admin.billing.products.edit', $product) }}" style="font-weight:600">{{ $product->name }}</a><div class="hint">{{ $product->typeLabel() }}</div></td>
                                <td class="muted">
                                    @if ($product->type === 'vps') {{ $product->package?->name ?? __('brak pakietu') }}
                                    @else {{ $product->plan?->name ?? __('brak planu') }} @endif
                                </td>
                                <td class="nowrap">
                                    @foreach ($product->configuredPrices() as $cycle => $amount)
                                        <div @unless ($category->allowsCycle($cycle)) class="muted" style="text-decoration:line-through" title="{{ __('kategoria nie dopuszcza') }}" @endunless>
                                            {{ $amount === 0 ? __('za darmo') : Money::format($amount) }} <span class="muted">{{ Cycle::per($cycle) }}</span></div>
                                    @endforeach
                                </td>
                                <td class="num">{{ $product->live_count }}@if ($product->stock !== null) / {{ $product->stock }}@endif</td>
                                <td>
                                    @if (! $product->deliverable()) <span class="pill critical">{{ __('pakiet/plan nieaktywny') }}</span>
                                    @elseif ($product->is_active) <span class="pill ok">{{ __('w sprzedaży') }}</span>
                                    @else <span class="pill neutral">{{ __('wyłączony') }}</span> @endif
                                </td>
                                <td style="text-align:right">
                                    <form method="POST" action="{{ route('panel.admin.billing.products.destroy', $product) }}" style="margin:0" data-confirm="{{ __('Usunąć produkt :name?', ['name' => $product->name]) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Usuń') }}"><x-icon name="trash" :size="14"/></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach

    <div class="card">
        <h3 class="card-title">{{ __('Nowa kategoria') }}</h3>
        <form method="POST" action="{{ route('panel.admin.billing.categories.store') }}" class="filter-bar">
            @csrf <input type="hidden" name="is_active" value="1">
            <div class="field" style="margin:0"><label for="c-name">{{ __('Nazwa') }}</label><input id="c-name" name="name" required maxlength="100" placeholder="{{ __('np. VPS KVM') }}"></div>
            <div class="field" style="margin:0; flex:1"><label for="c-desc">{{ __('Opis') }}</label><input id="c-desc" name="description" maxlength="500"></div>
            <button class="btn" type="submit"><x-icon name="plus" :size="15"/> {{ __('Dodaj') }}</button>
        </form>
    </div>
@endsection
