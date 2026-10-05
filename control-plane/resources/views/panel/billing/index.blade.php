@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Domain\Billing\Cycle')

@section('title', __('Rozliczenia'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Rozliczenia') }}</h1>
            <p class="lede">{{ __('Twoje usługi, faktury i portfel.') }}</p>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('panel.billing.wallet') }}"><x-icon name="wallet" :size="15"/> {{ __('Doładuj portfel') }}</a>
            <a class="btn btn-primary" href="{{ route('panel.store') }}"><x-icon name="plus" :size="15"/> {{ __('Zamów usługę') }}</a>
        </div>
    </div>

    <div class="grid grid-3" style="margin-bottom:16px">
        <a class="stat stat-link" href="{{ route('panel.billing.wallet') }}"><div class="stat-label">{{ __('Saldo portfela') }}</div><div class="stat-value" @if ($balance < 0) style="color:var(--critical)" @endif>{{ Money::format($balance) }}</div></a>
        <div class="stat"><div class="stat-label">{{ __('Aktywne usługi') }}</div><div class="stat-value">{{ $services->where('status', 'active')->count() }}</div></div>
        <div class="stat"><div class="stat-label">{{ __('Do zapłaty') }}</div><div class="stat-value" @if ($unpaid->isNotEmpty()) style="color:var(--warn)" @endif>{{ Money::format((int) $unpaid->sum('total')) }}</div></div>
    </div>

    @foreach ($unpaid as $invoice)
        <div class="alert {{ $invoice->isOverdue() ? 'alert-error' : 'alert-warning' }}">
            {{ __('Faktura :number na :total — termin :date.', ['number' => $invoice->number, 'total' => Money::format($invoice->total, $invoice->currency), 'date' => $invoice->due_at?->format('d.m.Y')]) }}
            <a href="{{ route('panel.billing.invoice', $invoice) }}"><strong>{{ __('Zapłać') }}</strong></a>
        </div>
    @endforeach

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Usługi') }}</h3></div>
        @if ($services->isEmpty())
            <p class="empty-note">{{ __('Nie masz jeszcze żadnych usług.') }} <a href="{{ route('panel.store') }}">{{ __('Przejdź do sklepu') }}</a></p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Usługa') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Cena') }}</th><th>{{ __('Następna płatność') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($services as $service)
                        <tr>
                            <td><a href="{{ route('panel.billing.service', $service) }}" style="font-weight:600">{{ $service->name }}</a></td>
                            <td><span class="pill {{ $service->statusTone() }}">{{ $service->statusLabel() }}</span></td>
                            <td class="nowrap">{{ Money::format(\App\Domain\Billing\Billing::gross($service->amount)) }} <span class="muted">{{ $service->periodLabel() }}</span></td>
                            <td class="muted nowrap">{{ $service->next_due_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td style="text-align:right">@if ($url = $service->resourceUrl()) <a class="btn btn-sm" href="{{ $url }}">{{ __('Zarządzaj') }}</a> @endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card flush dash-section" style="margin-top:16px">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Faktury') }}</h3></div>
        @if ($invoices->isEmpty())
            <p class="empty-note">{{ __('Brak faktur.') }}</p>
        @else
            @include('panel.billing._invoice-table', ['invoices' => $invoices, 'admin' => false])
        @endif
    </div>
    {{ $invoices->links() }}

    @if ($ended->isNotEmpty())
        <details class="form-block" style="margin-top:16px">
            <summary>{{ __('Zakończone usługi') }}</summary>
            <ul class="plain-list">
                @foreach ($ended as $service)
                    <li><a href="{{ route('panel.billing.service', $service) }}">{{ $service->name }}</a> <span class="pill neutral plain">{{ $service->statusLabel() }}</span>
                        @if ($service->last_error) <span class="hint">{{ $service->last_error }}</span> @endif</li>
                @endforeach
            </ul>
        </details>
    @endif
@endsection
