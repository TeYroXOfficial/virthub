@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Domain\Billing\Cycle')
@use('App\Domain\Billing\Billing')

@section('title', $product->name)

@php
    $gross = collect($prices)->map(fn ($a) => Billing::gross($a));
    $setup = $product->setup_fee > 0 ? Billing::gross($product->setup_fee) : 0;
    $minMetered = Billing::money('min_balance_metered');
    $defaultCycle = old('cycle', array_key_exists('monthly', $prices) ? 'monthly' : array_key_first($prices));
@endphp

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.store') }}" style="font-size:13px">{{ __('← Sklep') }}</a>
            <h1>{{ $product->name }}</h1>
            <div class="meta-line">
                <span>{{ $product->category?->name }}</span>
                @if ($product->type === 'vps' && $product->package)
                    <span class="sep">·</span><span>{{ $product->package->vcpu }} vCPU · {{ round($product->package->ram_mb / 1024, 1) }} GB RAM · {{ $product->package->disk_gb }} GB</span>
                @elseif ($product->type === 'external')
                    @php $ext = $product->external_config ?? []; @endphp
                    @if (! empty($ext['cpu']) || ! empty($ext['ram_mb']))
                        <span class="sep">·</span><span>{{ $ext['cpu'] ?? '?' }} vCPU · {{ round(($ext['ram_mb'] ?? 0) / 1024, 1) }} GB RAM · {{ $ext['disk_gb'] ?? '?' }} GB</span>
                    @endif
                @elseif ($product->plan)
                    <span class="sep">·</span><span>{{ round($product->plan->memory_mb / 1024, 1) }} GB RAM · {{ round($product->plan->disk_mb / 1024, 1) }} GB</span>
                @endif
                @if ($remaining !== null) <span class="sep">·</span><span>{{ trans_choice(':count sztuka dostępna|:count sztuki dostępne|:count sztuk dostępnych', $remaining) }}</span> @endif
            </div>
        </div>
    </div>

    @if ($product->description) <p class="muted" style="max-width:760px">{{ $product->description }}</p> @endif
    @if ($product->keepalive_interval)
        <div class="alert alert-info" style="max-width:760px">{{ __('Ta usługa wymaga potwierdzania aktywności: co :period kliknij „Przedłuż” w panelu, inaczej zostanie zawieszona.', ['period' => \App\Domain\Billing\Cycle::duration($product->keepalive_interval)]) }}</div>
    @endif

    <form method="POST" action="{{ route('panel.store.order', $product) }}" class="order-layout" id="order-form">
        @csrf
        <div>
            <div class="card">
                <h3 class="card-title">{{ __('Okres rozliczeniowy') }}</h3>
                <div class="cycle-grid">
                    @foreach ($gross as $cycle => $amount)
                        <label class="cycle-option">
                            <input type="radio" name="cycle" value="{{ $cycle }}" data-amount="{{ $amount }}" data-metered="{{ $product->renews($cycle) && Cycle::metered($cycle) ? 1 : 0 }}" @checked($defaultCycle === $cycle)>
                            <span>
                                <strong>{{ $product->renews($cycle) ? Cycle::label($cycle) : Cycle::duration($cycle) }}</strong>
                                <span class="price-sm">@if ($amount === 0) {{ __('Za darmo') }} @else {{ Money::format($amount) }} <span class="muted">{{ $product->renews($cycle) ? Cycle::per($cycle) : '' }}</span> @endif</span>
                                @unless ($product->renews($cycle))
                                    <span class="hint">{{ __('jednorazowo na :period — potem usługa się kończy', ['period' => Cycle::duration($cycle)]) }}</span>
                                @endunless
                                @if ($product->renews($cycle) && Cycle::metered($cycle) && $amount > 0)
                                    <span class="hint">{{ __('z portfela, ≈ :month / mies.', ['month' => Money::format(Money::roundCents(intdiv($amount * 730, Cycle::hours($cycle))))]) }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('cycle') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
            </div>

            @if ($locations->isNotEmpty())
                <div class="card" style="margin-top:16px">
                    <h3 class="card-title">{{ __('Lokalizacja') }}</h3>
                    <div class="field" style="margin:0">
                        <select name="location" aria-label="{{ __('Lokalizacja') }}" @if ($product->hypervisor_group_ids) required @endif>
                            @unless ($product->hypervisor_group_ids) <option value="">{{ __('Automatycznie — najmniej obciążona') }}</option> @endunless
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected(old('location') == $location->id)>{{ $location->publicName() }}</option>
                            @endforeach
                        </select>
                        @error('location') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                    </div>
                </div>
            @elseif ($product->hypervisor_group_ids)
                <div class="alert alert-warning" style="margin-top:16px">{{ __('W tej chwili żadna lokalizacja nie przyjmuje nowych usług tego typu.') }}</div>
            @endif

            <div class="card" style="margin-top:16px">
                <h3 class="card-title">{{ __('Konfiguracja') }}</h3>
                @if ($product->type === 'external')
                    <div class="field">
                        <label for="o-image">{{ __('System operacyjny') }}</label>
                        <select id="o-image" name="image" required>
                            @foreach ($product->externalImages() as $image)
                                <option value="{{ $image['id'] }}" @selected(old('image') == $image['id'])>{{ $image['name'] }}</option>
                            @endforeach
                        </select>
                        @error('image') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                    </div>
                @endif
                @if ($product->type === 'vps')
                    <div class="field">
                        <label for="o-template">{{ __('System operacyjny') }}</label>
                        <select id="o-template" name="template" required>
                            @foreach ($templates as $t)
                                <option value="{{ $t->id }}" @selected(old('template') == $t->id)>{{ $t->name }}</option>
                            @endforeach
                        </select>
                        @error('template') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                    </div>
                @endif
                @if (in_array($product->type, ['vps', 'external'], true))
                    <div class="field">
                        <label for="o-host">{{ __('Nazwa hosta') }}</label>
                        <input id="o-host" name="hostname" required maxlength="253" placeholder="vps1.example.com" value="{{ old('hostname') }}">
                        @error('hostname') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                    </div>
                    <div class="field" style="margin:0">
                        <label for="o-key">{{ __('Klucz SSH (opcjonalnie)') }}</label>
                        <textarea id="o-key" name="ssh_key" rows="2" placeholder="ssh-ed25519 AAAA…">{{ old('ssh_key') }}</textarea>
                        @error('ssh_key') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="field">
                        <label for="o-egg">{{ __('Aplikacja') }}</label>
                        <select id="o-egg" name="egg" required>
                            @foreach ($eggs->groupBy('category') as $group => $list)
                                <optgroup label="{{ $group ?: __('Inne') }}">
                                    @foreach ($list as $egg)
                                        <option value="{{ $egg->id }}" @selected(old('egg') == $egg->id)>{{ $egg->displayName() }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('egg') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                    </div>
                    <div class="field" style="margin:0">
                        <label for="o-name">{{ __('Nazwa') }}</label>
                        <input id="o-name" name="name" required maxlength="60" value="{{ old('name') }}" placeholder="{{ __('np. Serwer survival') }}">
                        @error('name') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
        </div>

        <aside class="card order-summary">
            <h3 class="card-title">{{ __('Podsumowanie') }}</h3>
            <dl class="kv">
                <dt>{{ __('Cena') }}</dt><dd><span id="sum-price">—</span></dd>
                @if ($setup > 0) <dt>{{ __('Opłata instalacyjna') }}</dt><dd>{{ Money::format($setup) }}</dd> @endif
                <dt>{{ __('Do zapłaty teraz') }}</dt><dd><strong id="sum-total">—</strong></dd>
                <dt>{{ __('Saldo portfela') }}</dt><dd>{{ Money::format($balance) }}</dd>
            </dl>
            <p class="hint">{{ Billing::taxRate() > 0 ? (Billing::pricesIncludeTax() ? __('Ceny zawierają VAT :rate%.', ['rate' => Billing::taxRate() / 100]) : __('Do cen doliczany jest VAT :rate%.', ['rate' => Billing::taxRate() / 100])) : '' }}</p>

            <div id="pay-recurring">
                <label class="check-line"><input type="radio" name="payment" value="wallet" @checked(old('payment', $balance > 0 ? 'wallet' : 'invoice') === 'wallet')> {{ __('Zapłać z portfela (jeśli wystarczy środków)') }}</label>
                <label class="check-line"><input type="radio" name="payment" value="invoice" @checked(old('payment', $balance > 0 ? 'wallet' : 'invoice') === 'invoice')> {{ __('Wystaw fakturę — zapłacę kartą / PayPalem / przelewem') }}</label>
            </div>
            <p id="pay-metered" class="hint" hidden>{{ __('Opłata pobierana z portfela za każdą rozpoczętą godzinę/dobę. Wymagane saldo: co najmniej :min.', ['min' => Money::format($minMetered)]) }}
                @if ($balance < $minMetered) <a href="{{ route('panel.billing.wallet') }}">{{ __('Doładuj portfel') }}</a> @endif</p>
            @error('payment') <div class="alert alert-error" style="margin:10px 0">{{ $message }}</div> @enderror

            <label class="check-line" style="margin-top:10px"><input type="checkbox" name="accept" value="1" required> {{ __('Akceptuję regulamin i warunki rozliczeń') }}</label>
            @error('accept') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
            <button class="btn btn-primary" type="submit" style="width:100%; margin-top:12px">{{ __('Zamawiam') }}</button>
        </aside>
    </form>
@endsection

@push('scripts')
    <script>
        (function () {
            var form = document.getElementById('order-form');
            var setup = {{ $setup }};
            var currency = @js(Billing::currency());
            function fmt(v) {
                var d = v % 100 === 0 ? 2 : 4;
                var s = (v / 10000).toFixed(d);
                if (d === 4) { s = s.replace(/0+$/, ''); if (s.split('.')[1].length < 2) s += '0'; }
                var p = s.split('.');
                p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
                return p.join(',') + ' ' + currency;
            }
            function update() {
                var picked = form.querySelector('input[name=cycle]:checked');
                if (!picked) return;
                var amount = parseInt(picked.dataset.amount, 10);
                var metered = picked.dataset.metered === '1';
                var free = amount + setup === 0;
                document.getElementById('sum-price').textContent = amount === 0 ? @js(__('Za darmo')) : fmt(amount);
                document.getElementById('sum-total').textContent = fmt(amount + setup);
                document.getElementById('pay-recurring').hidden = metered || free;
                document.getElementById('pay-metered').hidden = !metered || free;
                if (metered) { form.querySelector('input[name=payment][value=wallet]').checked = true; }
            }
            form.addEventListener('change', update);
            update();
        })();
    </script>
@endpush
