@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Models\Payment')

@section('title', __('Faktura :number', ['number' => $invoice->number]))

@php $cur = $invoice->currency; @endphp

@section('content')
    <div class="page-header no-print">
        <div>
            <a class="muted" href="{{ $admin ? route('panel.admin.billing.invoices') : route('panel.billing') }}" style="font-size:13px">{{ $admin ? __('← Faktury') : __('← Rozliczenia') }}</a>
            <h1>{{ __('Faktura :number', ['number' => $invoice->number]) }}</h1>
            <div class="meta-line"><span class="pill {{ $invoice->statusTone() }}">{{ $invoice->statusLabel() }}</span>
                @if ($admin && $invoice->user) <span class="sep">·</span><a href="{{ route('panel.admin.billing.customer', $invoice->user) }}">{{ $invoice->user->email }}</a> @endif</div>
        </div>
        <div class="actions"><button class="btn" type="button" onclick="window.print()"><x-icon name="download" :size="15"/> {{ __('Drukuj / PDF') }}</button></div>
    </div>

    <div class="invoice-layout">
        <article class="card invoice-doc">
            <header class="invoice-head">
                <div>
                    <h2 style="margin:0">{{ $invoice->type === 'topup' ? __('Faktura — doładowanie portfela') : __('Faktura') }}</h2>
                    <div class="mono">{{ $invoice->number }}</div>
                </div>
                <dl class="kv invoice-dates">
                    <dt>{{ __('Data wystawienia') }}</dt><dd>{{ $invoice->created_at->format('d.m.Y') }}</dd>
                    <dt>{{ __('Termin płatności') }}</dt><dd>{{ $invoice->due_at?->format('d.m.Y') ?? '—' }}</dd>
                    @if ($invoice->paid_at) <dt>{{ __('Zapłacono') }}</dt><dd>{{ $invoice->paid_at->format('d.m.Y H:i') }}</dd> @endif
                </dl>
            </header>
            <div class="grid grid-2 invoice-parties">
                <div>
                    <div class="hint">{{ __('Sprzedawca') }}</div>
                    <strong>{{ $invoice->seller['name'] ?? '' ?: config('virthub.brand') }}</strong>
                    @if (! empty($invoice->seller['address'])) <div style="white-space:pre-line">{{ $invoice->seller['address'] }}</div> @endif
                    @if (! empty($invoice->seller['tax_id'])) <div>{{ __('NIP') }}: {{ $invoice->seller['tax_id'] }}</div> @endif
                </div>
                <div>
                    <div class="hint">{{ __('Nabywca') }}</div>
                    <strong>{{ ($invoice->buyer['name'] ?? '') ?: ($invoice->buyer['email'] ?? '') }}</strong>
                    <div>{{ $invoice->buyer['email'] ?? '' }}</div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Pozycja') }}</th><th style="text-align:right">{{ __('Kwota') }}</th></tr></thead>
                    <tbody>
                    @foreach ($invoice->items as $item)
                        <tr><td>{{ $item->description }}</td><td class="num nowrap">{{ Money::format($item->amount, $cur) }}</td></tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                        @if ($invoice->tax_rate > 0)
                            <tr><td style="text-align:right">{{ __('Netto') }}</td><td class="num nowrap">{{ Money::format($invoice->subtotal, $cur) }}</td></tr>
                            <tr><td style="text-align:right">{{ __('VAT :rate%', ['rate' => $invoice->tax_rate / 100]) }}</td><td class="num nowrap">{{ Money::format($invoice->tax, $cur) }}</td></tr>
                        @endif
                        <tr><td style="text-align:right"><strong>{{ __('Razem do zapłaty') }}</strong></td><td class="num nowrap"><strong>{{ Money::format($invoice->total, $cur) }}</strong></td></tr>
                    </tfoot>
                </table>
            </div>
            @if ($invoice->notes) <p class="hint" style="white-space:pre-line">{{ $invoice->notes }}</p> @endif
        </article>

        <aside class="no-print">
            @if ($invoice->isUnpaid() && ! $admin)
                <div class="card">
                    <h3 class="card-title">{{ __('Zapłać :amount', ['amount' => Money::format($invoice->total, $cur)]) }}</h3>
                    @error('payment') <div class="alert alert-error">{{ $message }}</div> @enderror
                    @if ($invoice->type !== 'topup')
                        <form method="POST" action="{{ route('panel.billing.invoice.wallet', $invoice) }}" style="margin:0 0 10px">
                            @csrf
                            <button class="btn btn-primary" type="submit" style="width:100%" @disabled($balance < $invoice->total)><x-icon name="wallet" :size="15"/> {{ __('Zapłać z portfela') }}</button>
                        </form>
                        <p class="hint">{{ __('Saldo portfela') }}: {{ Money::format($balance) }}</p>
                        @if ($balance < $invoice->total) <p class="hint" style="margin-top:-6px">{{ __('Za mało środków w portfelu.') }} <a href="{{ route('panel.billing.wallet') }}">{{ __('Doładuj') }}</a></p> @endif
                    @endif
                    @foreach ($gateways as $key => $label)
                        <form method="POST" action="{{ route('panel.billing.pay', [$invoice, $key]) }}" style="margin:0 0 10px">
                            @csrf
                            <button class="btn" type="submit" style="width:100%">{{ __('Zapłać przez :gateway', ['gateway' => $label]) }}</button>
                        </form>
                    @endforeach
                    @if ($gateways === [] && $invoice->type === 'topup')
                        <p class="hint">{{ __('Płatności online nie są jeszcze włączone. Skontaktuj się z nami, aby opłacić fakturę przelewem.') }}</p>
                    @endif
                </div>
            @endif

            @if ($admin)
                <div class="card">
                    <h3 class="card-title">{{ __('Działania') }}</h3>
                    @error('invoice') <div class="alert alert-error">{{ $message }}</div> @enderror
                    @if ($invoice->isUnpaid())
                        <form method="POST" action="{{ route('panel.admin.billing.invoice.action', $invoice) }}" style="margin:0 0 10px">
                            @csrf <input type="hidden" name="action" value="mark_paid">
                            <div class="field" style="margin-bottom:8px"><input name="reference" maxlength="150" placeholder="{{ __('Tytuł/nr przelewu (opcjonalnie)') }}" aria-label="{{ __('Identyfikator płatności') }}"></div>
                            <button class="btn btn-primary" type="submit" style="width:100%">{{ __('Oznacz jako zapłaconą') }}</button>
                        </form>
                        @if ($invoice->type !== 'topup')
                            <form method="POST" action="{{ route('panel.admin.billing.invoice.action', $invoice) }}" style="margin:0 0 10px">
                                @csrf <input type="hidden" name="action" value="wallet">
                                <button class="btn" type="submit" style="width:100%" @disabled($balance < $invoice->total)>{{ __('Opłać z portfela klienta (:balance)', ['balance' => Money::format($balance)]) }}</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('panel.admin.billing.invoice.action', $invoice) }}" data-confirm="{{ __('Anulować fakturę?') }}" style="margin:0">
                            @csrf <input type="hidden" name="action" value="cancel">
                            <button class="btn btn-danger" type="submit" style="width:100%">{{ __('Anuluj fakturę') }}</button>
                        </form>
                    @elseif ($invoice->status === 'paid' && $invoice->type !== 'topup')
                        <form method="POST" action="{{ route('panel.admin.billing.invoice.action', $invoice) }}" data-confirm="{{ __('Zwrócić :amount na saldo portfela klienta? Usługa działa dalej.', ['amount' => Money::format($invoice->total, $cur)]) }}" style="margin:0">
                            @csrf <input type="hidden" name="action" value="refund">
                            <button class="btn" type="submit" style="width:100%">{{ __('Zwrot do portfela') }}</button>
                        </form>
                    @else
                        <p class="muted">{{ __('Brak dostępnych działań.') }}</p>
                    @endif
                </div>
            @endif

            @if ($invoice->payments->isNotEmpty())
                <div class="card" style="margin-top:16px">
                    <h3 class="card-title">{{ __('Płatności') }}</h3>
                    <ul class="plain-list">
                        @foreach ($invoice->payments as $payment)
                            <li><strong>{{ Payment::gatewayLabel($payment->gateway) }}</strong> · {{ Money::format($payment->amount, $payment->currency) }}
                                <div class="hint">{{ $payment->created_at->format('d.m.Y H:i') }}@if ($admin && $payment->reference) · <span class="mono">{{ $payment->reference }}</span>@endif</div></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </aside>
    </div>
@endsection
