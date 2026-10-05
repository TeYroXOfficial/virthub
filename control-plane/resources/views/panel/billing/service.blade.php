@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Domain\Billing\Cycle')
@use('App\Domain\Billing\Billing')

@section('title', $service->name)

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.billing') }}" style="font-size:13px">{{ __('← Rozliczenia') }}</a>
            <h1>{{ $service->name }}</h1>
            <div class="meta-line"><span class="pill {{ $service->statusTone() }}">{{ $service->statusLabel() }}</span></div>
        </div>
        @if ($url = $service->resourceUrl())
            <div class="actions"><a class="btn btn-primary" href="{{ $url }}">{{ __('Zarządzaj') }}</a></div>
        @endif
    </div>

    <div class="grid grid-2">
        <div class="card">
            <dl class="kv">
                <dt>{{ __('Produkt') }}</dt><dd>{{ $service->product?->name ?? '—' }}</dd>
                <dt>{{ __('Cykl') }}</dt><dd>{{ Cycle::label($service->cycle) }}</dd>
                <dt>{{ __('Cena') }}</dt><dd>{{ Money::format(Billing::gross($service->amount)) }} {{ Cycle::per($service->cycle) }}</dd>
                <dt>{{ $service->metered() ? __('Następne naliczenie') : __('Opłacone do') }}</dt><dd>{{ $service->next_due_at?->format('d.m.Y H:i') ?? '—' }}</dd>
                <dt>{{ __('Zamówiona') }}</dt><dd>{{ $service->created_at->format('d.m.Y H:i') }}</dd>
                @if ($service->last_error) <dt>{{ __('Błąd') }}</dt><dd style="color:var(--critical)">{{ $service->last_error }}</dd> @endif
            </dl>
        </div>
        <div class="card">
            <h3 class="card-title">{{ __('Rezygnacja') }}</h3>
            @error('service') <div class="alert alert-error">{{ $message }}</div> @enderror
            @if ($service->status === 'pending' || ($service->isLive() && ! $service->cancel_at_period_end))
                <p class="muted">
                    @if ($service->status === 'pending') {{ __('Zamówienie nie zostało opłacone — możesz je anulować.') }}
                    @elseif ($service->metered()) {{ __('Usługa godzinowa zostanie usunięta od razu razem z danymi. Opłaty przestaną być naliczane.') }}
                    @else {{ __('Usługa będzie działać do końca opłaconego okresu (:date), potem zostanie usunięta razem z danymi.', ['date' => $service->next_due_at?->format('d.m.Y')]) }}
                    @endif
                </p>
                <form method="POST" action="{{ route('panel.billing.service.cancel', $service) }}" data-confirm="{{ __('Na pewno zrezygnować z usługi :name?', ['name' => $service->name]) }}">
                    @csrf <input type="hidden" name="confirm" value="1">
                    <button class="btn btn-danger" type="submit">{{ $service->status === 'pending' ? __('Anuluj zamówienie') : __('Zrezygnuj z usługi') }}</button>
                </form>
            @elseif ($service->cancel_at_period_end)
                <p class="muted">{{ __('Rezygnacja zgłoszona — usługa zostanie usunięta :date.', ['date' => $service->next_due_at?->format('d.m.Y H:i')]) }}</p>
            @else
                <p class="muted">{{ __('Usługa jest zakończona.') }}</p>
            @endif
        </div>
    </div>

    @if ($service->invoiceItems->isNotEmpty())
        <div class="card flush" style="margin-top:16px">
            <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Faktury tej usługi') }}</h3></div>
            @include('panel.billing._invoice-table', ['invoices' => $service->invoiceItems->pluck('invoice')->filter()->unique('id')->sortByDesc('id'), 'admin' => false])
        </div>
    @endif
@endsection
