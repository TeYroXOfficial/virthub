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

    @include('panel.billing._keepalive', ['service' => $service])

    <div class="grid grid-2">
        <div class="card">
            <dl class="kv">
                <dt>{{ __('Produkt') }}</dt><dd>{{ $service->product?->name ?? '—' }}</dd>
                <dt>{{ __('Okres') }}</dt><dd>{{ $service->renews ? Cycle::label($service->cycle) : __(':period, jednorazowo', ['period' => Cycle::duration($service->cycle)]) }}</dd>
                <dt>{{ __('Cena') }}</dt><dd>{{ Money::format(Billing::gross($service->amount)) }} {{ $service->periodLabel() }}</dd>
                <dt>{{ $service->metered() ? __('Następne naliczenie') : __('Opłacone do') }}</dt><dd>{{ $service->next_due_at?->format('d.m.Y H:i') ?? '—' }}</dd>
                <dt>{{ __('Zamówiona') }}</dt><dd>{{ $service->created_at->format('d.m.Y H:i') }}</dd>
                @if ($service->last_error) <dt>{{ __('Błąd') }}</dt><dd style="color:var(--critical)">{{ $service->last_error }}</dd> @endif
            </dl>
        </div>
        <div class="card">
            <h3 class="card-title">{{ __('Rezygnacja') }}</h3>
            @include('panel.billing._cancel', ['service' => $service])
        </div>
    </div>

    @if ($service->invoiceItems->isNotEmpty())
        <div class="card flush" style="margin-top:16px">
            <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Faktury tej usługi') }}</h3></div>
            @include('panel.billing._invoice-table', ['invoices' => $service->invoiceItems->pluck('invoice')->filter()->unique('id')->sortByDesc('id'), 'admin' => false])
        </div>
    @endif
@endsection
