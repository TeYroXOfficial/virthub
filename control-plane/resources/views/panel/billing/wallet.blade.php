@extends('layouts.panel')
@use('App\Domain\Billing\Money')

@section('title', __('Portfel'))

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.billing') }}" style="font-size:13px">{{ __('← Rozliczenia') }}</a>
            <h1>{{ __('Portfel') }}</h1>
            <p class="lede">{{ __('Środki w portfelu opłacają faktury i usługi rozliczane godzinowo.') }}</p>
        </div>
    </div>

    <div class="grid grid-2" style="margin-bottom:16px">
        <div class="card">
            <div class="stat-label">{{ __('Saldo') }}</div>
            <div class="stat-value" style="font-size:32px; @if ($balance < 0) color:var(--critical) @endif">{{ Money::format($balance) }}</div>
            @if ($balance < 0) <p class="hint" style="color:var(--critical)">{{ __('Saldo ujemne — usługi godzinowe są zawieszone do czasu doładowania.') }}</p> @endif
        </div>
        <div class="card">
            <h3 class="card-title">{{ __('Doładuj') }}</h3>
            <form method="POST" action="{{ route('panel.billing.topup') }}" class="filter-bar">
                @csrf
                <div class="field" style="margin:0">
                    <label for="w-amount">{{ __('Kwota') }}</label>
                    <input id="w-amount" name="amount" inputmode="decimal" required value="{{ old('amount', Money::toDecimal(max($min, 500000))) }}" style="max-width:160px">
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Dalej') }}</button>
            </form>
            @error('amount') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
            <p class="hint">{{ __('Od :min do :max. Po kliknięciu wybierzesz sposób płatności.', ['min' => Money::format(max(100, $min)), 'max' => Money::format($max)]) }}</p>
        </div>
    </div>

    <div class="card flush">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Historia') }}</h3></div>
        @if ($transactions->isEmpty())
            <p class="empty-note">{{ __('Brak operacji.') }}</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Data') }}</th><th>{{ __('Rodzaj') }}</th><th>{{ __('Opis') }}</th><th style="text-align:right">{{ __('Kwota') }}</th><th style="text-align:right">{{ __('Saldo po') }}</th></tr></thead>
                    <tbody>
                    @foreach ($transactions as $tx)
                        <tr>
                            <td class="muted nowrap">{{ $tx->created_at->format('d.m.Y H:i') }}</td>
                            <td>{{ $tx->typeLabel() }}</td>
                            <td>{{ $tx->description }}</td>
                            <td class="num nowrap" style="color:var({{ $tx->amount < 0 ? '--critical' : '--ok' }})">{{ $tx->amount > 0 ? '+' : '' }}{{ Money::format($tx->amount) }}</td>
                            <td class="num nowrap muted">{{ Money::format($tx->balance_after) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    {{ $transactions->links() }}
@endsection
