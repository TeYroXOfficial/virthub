@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Domain\Billing\Cycle')

@section('title', $customer->email)

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.billing.customers') }}" style="font-size:13px">{{ __('← Portfele') }}</a>
            <h1>{{ $customer->name ?: $customer->email }}</h1>
            <div class="meta-line"><span>{{ $customer->email }}</span>
                @if ($customer->isSuspended()) <span class="sep">·</span><span class="pill critical">{{ __('konto zawieszone') }}</span> @endif</div>
        </div>
        <div class="actions">
            @if (auth()->user()->hasPermission('admin.users'))
                <a class="btn" href="{{ route('panel.admin.users.edit', $customer) }}"><x-icon name="users" :size="15"/> {{ __('Konto') }}</a>
            @endif
            @include('panel.admin._impersonate-button', ['target' => $customer])
        </div>
    </div>

    <div class="grid grid-2" style="margin-bottom:16px">
        <div class="card">
            <div class="stat-label">{{ __('Saldo portfela') }}</div>
            <div class="stat-value" style="font-size:30px; @if ($customer->wallet_balance < 0) color:var(--critical) @endif">{{ Money::format($customer->wallet_balance) }}</div>
        </div>
        <div class="card">
            <h3 class="card-title">{{ __('Korekta salda') }}</h3>
            <form method="POST" action="{{ route('panel.admin.billing.customer.wallet', $customer) }}" class="filter-bar" data-confirm="{{ __('Zmienić saldo klienta?') }}">
                @csrf
                <div class="field" style="margin:0"><label for="a-amount">{{ __('Kwota (+/−)') }}</label><input id="a-amount" name="amount" inputmode="decimal" required placeholder="50 / -12,50" style="max-width:130px"></div>
                <div class="field" style="margin:0; flex:1"><label for="a-desc">{{ __('Opis (widzi go klient)') }}</label><input id="a-desc" name="description" required maxlength="200" placeholder="{{ __('np. Przelew bankowy 12.10') }}"></div>
                <button class="btn" type="submit">{{ __('Zapisz') }}</button>
            </form>
            @error('amount') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Usługi') }}</h3>
            <a class="card-more" href="{{ route('panel.admin.billing.services', ['q' => $customer->email]) }}">{{ __('Wszystkie') }} →</a></div>
        @if ($services->isEmpty())
            <p class="empty-note">{{ __('Brak usług.') }}</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Usługa') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Cena') }}</th><th>{{ __('Następna płatność') }}</th></tr></thead>
                    <tbody>
                    @foreach ($services as $s)
                        <tr>
                            <td><a href="{{ route('panel.admin.billing.service', $s) }}" style="font-weight:600">{{ $s->name }}</a></td>
                            <td><span class="pill {{ $s->statusTone() }}">{{ $s->statusLabel() }}</span></td>
                            <td class="nowrap">{{ $s->amount === 0 ? __('za darmo') : Money::format($s->amount) }} <span class="muted">{{ $s->periodLabel() }}</span></td>
                            <td class="muted nowrap">{{ $s->next_due_at?->format('d.m.Y H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card flush dash-section" style="margin-top:16px">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Faktury') }}</h3>
            <a class="card-more" href="{{ route('panel.admin.billing.invoices', ['q' => $customer->email]) }}">{{ __('Wszystkie') }} →</a></div>
        @if ($invoices->isEmpty())
            <p class="empty-note">{{ __('Brak faktur.') }}</p>
        @else
            @include('panel.billing._invoice-table', ['invoices' => $invoices, 'admin' => true, 'showCustomer' => false])
        @endif
    </div>

    <div class="card flush" style="margin-top:16px">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Historia portfela') }}</h3></div>
        @if ($transactions->isEmpty()) <p class="empty-note">{{ __('Brak operacji.') }}</p> @else
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
