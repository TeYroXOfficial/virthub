@use('App\Domain\Billing\Money')
<div class="table-wrap">
    <table>
        <thead><tr><th>{{ __('Numer') }}</th>@if ($admin)<th>{{ __('Klient') }}</th>@endif<th>{{ __('Rodzaj') }}</th><th>{{ __('Kwota') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Wystawiona') }}</th><th>{{ __('Termin') }}</th></tr></thead>
        <tbody>
        @foreach ($invoices as $invoice)
            <tr>
                <td><a class="mono" href="{{ $admin ? route('panel.admin.billing.invoice', $invoice) : route('panel.billing.invoice', $invoice) }}" style="font-weight:600">{{ $invoice->number }}</a></td>
                @if ($admin)<td>{{ $invoice->user?->email ?? '—' }}</td>@endif
                <td>{{ $invoice->type === 'topup' ? __('doładowanie') : __('usługi') }}</td>
                <td class="num nowrap">{{ Money::format($invoice->total, $invoice->currency) }}</td>
                <td><span class="pill {{ $invoice->statusTone() }}">{{ $invoice->statusLabel() }}</span></td>
                <td class="muted nowrap">{{ $invoice->created_at->format('d.m.Y') }}</td>
                <td class="muted nowrap">{{ $invoice->paid_at ? __('zapłacono :date', ['date' => $invoice->paid_at->format('d.m.Y')]) : ($invoice->due_at?->format('d.m.Y') ?? '—') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
