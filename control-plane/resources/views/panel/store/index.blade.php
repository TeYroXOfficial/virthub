@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Domain\Billing\Cycle')
@use('App\Domain\Billing\Billing')

@section('title', __('Sklep'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Sklep') }}</h1>
            <p class="lede">{{ __('Wybierz usługę — rozliczenie godzinowe z portfela albo abonament z fakturą.') }}</p>
        </div>
        <div class="actions"><a class="btn" href="{{ route('panel.billing.wallet') }}"><x-icon name="wallet" :size="15"/> {{ __('Portfel') }}</a></div>
    </div>

    @forelse ($categories as $category)
        <section class="dash-section" style="margin-bottom:24px">
            <h2 class="section-title">{{ $category->name }}</h2>
            @if ($category->description) <p class="muted" style="margin-top:-6px">{{ $category->description }}</p> @endif
            <div class="product-grid">
                @foreach ($category->products as $product)
                    @php
                        $prices = $product->priceMap();
                        // Na karcie cena miesięczna, a bez niej — najtańsza w przeliczeniu na miesiąc.
                        $cheapest = in_array(0, $prices, true) ? array_search(0, $prices, true)
                            : (array_key_exists('monthly', $prices) ? 'monthly'
                            : collect($prices)->sortBy(fn ($a, $c) => intdiv($a * 730, Cycle::hours($c)))->keys()->first());
                    @endphp
                    <article class="product-card">
                        <div>
                            <span class="pill neutral plain">{{ $product->typeLabel() }}</span>
                            <h3>{{ $product->name }}</h3>
                            @if ($product->type === 'vps' && $product->package)
                                <ul class="spec-list">
                                    <li><strong>{{ $product->package->vcpu }}</strong> vCPU</li>
                                    <li><strong>{{ round($product->package->ram_mb / 1024, 1) }} GB</strong> RAM</li>
                                    <li><strong>{{ $product->package->disk_gb }} GB</strong> {{ __('dysku') }}</li>
                                    @if ($product->package->bandwidth_gb) <li><strong>{{ $product->package->bandwidth_gb }} GB</strong> {{ __('transferu') }}</li> @endif
                                </ul>
                            @elseif ($product->plan)
                                <ul class="spec-list">
                                    <li><strong>{{ round($product->plan->memory_mb / 1024, 1) }} GB</strong> RAM</li>
                                    <li><strong>{{ round($product->plan->disk_mb / 1024, 1) }} GB</strong> {{ __('dysku') }}</li>
                                    @if ($product->plan->cpu_percent) <li><strong>{{ $product->plan->cpu_percent }}%</strong> CPU</li> @endif
                                </ul>
                            @endif
                            @if ($product->description) <p class="muted product-desc">{{ $product->description }}</p> @endif
                        </div>
                        <div>
                            <div class="price-line">
                                @if ($prices[$cheapest] === 0)
                                    <span class="price">{{ __('Za darmo') }}</span>
                                    <span class="muted">{{ $product->renews($cheapest) ? Cycle::label($cheapest) : __('na :period', ['period' => Cycle::duration($cheapest)]) }}</span>
                                @else
                                    <span class="price">{{ Money::format(Billing::gross($prices[$cheapest])) }}</span>
                                    <span class="muted">{{ $product->renews($cheapest) ? Cycle::per($cheapest) : __('za :period', ['period' => Cycle::duration($cheapest)]) }}</span>
                                @endif
                            </div>
                            @if (count($prices) > 1)
                                <div class="hint">{{ collect(array_keys($prices))->map(fn ($c) => $product->renews($c) ? Cycle::label($c) : Cycle::duration($c))->join(' · ') }}</div>
                            @endif
                            @if (($left = $product->remaining()) !== null && $left === 0)
                                <span class="btn" aria-disabled="true" style="width:100%; margin-top:12px; opacity:.6">{{ __('Wyprzedane') }}</span>
                            @else
                                <a class="btn btn-primary" style="width:100%; margin-top:12px" href="{{ route('panel.store.product', $product) }}">{{ __('Zamów') }}</a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        <div class="card empty"><x-icon name="package" :size="40"/><p>{{ __('Sklep jest jeszcze pusty.') }}</p></div>
    @endforelse
@endsection
