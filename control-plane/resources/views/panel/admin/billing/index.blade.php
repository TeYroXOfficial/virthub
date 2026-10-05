@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Models\Payment')

@section('title', __('Billing'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Billing') }}</h1>
            <p class="lede">{{ __('Sprzedaż, zaległości i stan portfeli klientów.') }}</p>
        </div>
        <div class="actions">
            <a class="btn" href="{{ route('panel.admin.billing.settings') }}"><x-icon name="sliders" :size="15"/> {{ __('Ustawienia') }}</a>
            <a class="btn btn-primary" href="{{ route('panel.admin.billing.products.create') }}"><x-icon name="plus" :size="15"/> {{ __('Nowy produkt') }}</a>
        </div>
    </div>

    @unless ($enabled)
        <div class="alert alert-warning">{{ __('Billing jest wyłączony — klienci zamawiają bezpośrednio, bez płatności. Włącz go w ustawieniach, gdy katalog będzie gotowy.') }}
            <a href="{{ route('panel.admin.billing.settings') }}">{{ __('Ustawienia') }}</a></div>
    @endunless

    <div class="grid grid-4" style="margin-bottom:16px">
        <div class="stat"><div class="stat-label">{{ __('Wpłaty w tym miesiącu (faktury)') }}</div><div class="stat-value">{{ Money::format($stats['month']) }}</div></div>
        <div class="stat"><div class="stat-label">{{ __('Opłaty godzinowe w tym miesiącu') }}</div><div class="stat-value">{{ Money::format(-$stats['metered']) }}</div></div>
        <a class="stat stat-link" href="{{ route('panel.admin.billing.invoices', ['status' => 'unpaid']) }}"><div class="stat-label"><i class="dot warning"></i> {{ __('Nieopłacone faktury') }}</div><div class="stat-value">{{ Money::format($stats['unpaid']) }}</div></a>
        <a class="stat stat-link" href="{{ route('panel.admin.billing.invoices', ['status' => 'overdue']) }}"><div class="stat-label"><i class="dot critical"></i> {{ __('Po terminie') }}</div><div class="stat-value">{{ $stats['overdue'] }}</div></a>
        <a class="stat stat-link" href="{{ route('panel.admin.billing.services', ['status' => 'active']) }}"><div class="stat-label"><i class="dot ok"></i> {{ __('Aktywne usługi') }}</div><div class="stat-value">{{ $stats['active'] }}</div></a>
        <a class="stat stat-link" href="{{ route('panel.admin.billing.services', ['status' => 'suspended']) }}"><div class="stat-label">{{ __('Zawieszone usługi') }}</div><div class="stat-value">{{ $stats['suspended'] }}</div></a>
        <a class="stat stat-link" href="{{ route('panel.admin.billing.customers') }}"><div class="stat-label">{{ __('Środki w portfelach') }}</div><div class="stat-value">{{ Money::format($stats['wallets']) }}</div></a>
    </div>

    <div class="card flush">
        <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Ostatnie płatności') }}</h3></div>
        @if ($recent->isEmpty())
            <p class="empty-note">{{ __('Brak płatności.') }}</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Data') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Faktura') }}</th><th>{{ __('Metoda') }}</th><th style="text-align:right">{{ __('Kwota') }}</th></tr></thead>
                    <tbody>
                    @foreach ($recent as $payment)
                        <tr>
                            <td class="muted nowrap">{{ $payment->created_at->format('d.m.Y H:i') }}</td>
                            <td>{{ $payment->invoice?->user?->email ?? '—' }}</td>
                            <td>@if ($payment->invoice) <a class="mono" href="{{ route('panel.admin.billing.invoice', $payment->invoice) }}">{{ $payment->invoice->number }}</a> @else — @endif</td>
                            <td>{{ Payment::gatewayLabel($payment->gateway) }}</td>
                            <td class="num nowrap">{{ Money::format($payment->amount, $payment->currency) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
