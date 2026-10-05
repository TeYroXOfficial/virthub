{{-- Statystyki billingu i wykresy — Billing → Przegląd i pulpit administracji. --}}
@php
    $money = fn (int $v) => \App\Domain\Billing\Money::format($v);
    $count = fn (int $v) => (string) $v;
    $toPoints = fn (array $series) => array_map(fn ($p) => [
        'label' => $p['date']->format('d.m'), 'tip' => $p['date']->translatedFormat('j F Y'), 'value' => $p['value'],
    ], $series);
    $incomeTotal = array_sum(array_column($daily['income'], 'value'));
    $servicesTotal = array_sum(array_column($daily['services'], 'value'));
    $maxProduct = max(1, max(array_column($byProduct, 'value') ?: [0]));
@endphp
<div class="grid grid-4" style="margin-bottom:16px">
    <div class="stat"><div class="stat-label">{{ __('Wpływy w tym miesiącu') }}</div><div class="stat-value">{{ $money($stats['income_month']) }}</div><div class="hint" style="margin:4px 0 0">{{ __('karta, PayPal, przelewy') }}</div></div>
    <div class="stat"><div class="stat-label">{{ __('Opłacone faktury za usługi') }}</div><div class="stat-value">{{ $money($stats['month']) }}</div></div>
    <div class="stat"><div class="stat-label">{{ __('Opłaty godzinowe w tym miesiącu') }}</div><div class="stat-value">{{ $money($stats['metered']) }}</div></div>
    <a class="stat stat-link" href="{{ route('panel.admin.billing.customers') }}"><div class="stat-label">{{ __('Środki w portfelach') }}</div><div class="stat-value">{{ $money($stats['wallets']) }}</div></a>
    <a class="stat stat-link" href="{{ route('panel.admin.billing.invoices', ['status' => 'unpaid']) }}"><div class="stat-label"><i class="dot warning"></i> {{ __('Nieopłacone faktury') }}</div><div class="stat-value">{{ $money($stats['unpaid']) }}</div></a>
    <a class="stat stat-link" href="{{ route('panel.admin.billing.invoices', ['status' => 'overdue']) }}"><div class="stat-label"><i class="dot critical"></i> {{ __('Po terminie') }}</div><div class="stat-value">{{ $stats['overdue'] }}</div></a>
    <a class="stat stat-link" href="{{ route('panel.admin.billing.services', ['status' => 'active']) }}"><div class="stat-label"><i class="dot ok"></i> {{ __('Aktywne usługi') }}</div><div class="stat-value">{{ $stats['active'] }}</div></a>
    <a class="stat stat-link" href="{{ route('panel.admin.billing.services', ['status' => 'suspended']) }}"><div class="stat-label">{{ __('Zawieszone usługi') }}</div><div class="stat-value">{{ $stats['suspended'] }}</div></a>
</div>

<div class="grid grid-3 dash-section">
    <div class="card">
        <h3 class="card-title" style="margin-bottom:2px">{{ __('Wpływy — ostatnie 30 dni') }}</h3>
        <p class="hint" style="margin:0 0 10px">{{ __('Razem :total', ['total' => $money($incomeTotal)]) }}</p>
        @include('panel.admin._bar-chart', ['points' => $toPoints($daily['income']), 'format' => $money, 'title' => __('Wpływy dzienne z ostatnich 30 dni')])
    </div>
    <div class="card">
        <h3 class="card-title" style="margin-bottom:2px">{{ __('Nowe usługi — ostatnie 30 dni') }}</h3>
        <p class="hint" style="margin:0 0 10px">{{ __('Razem :total', ['total' => $servicesTotal]) }}</p>
        @include('panel.admin._bar-chart', ['points' => $toPoints($daily['services']), 'format' => $count, 'title' => __('Nowe usługi dziennie z ostatnich 30 dni')])
    </div>
    <div class="card">
        <h3 class="card-title">{{ __('Aktywne usługi według produktu') }}</h3>
        @if ($byProduct === [])
            <p class="empty-note">{{ __('Brak aktywnych usług.') }}</p>
        @else
            <ul class="hbar-list">
                @foreach ($byProduct as $row)
                    <li title="{{ $row['label'] }}: {{ $row['value'] }}">
                        <span class="hbar-label">{{ $row['label'] }}</span>
                        <span class="hbar-track"><i style="width: {{ max(2, $row['value'] / $maxProduct * 100) }}%"></i></span>
                        <span class="hbar-value num">{{ $row['value'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
