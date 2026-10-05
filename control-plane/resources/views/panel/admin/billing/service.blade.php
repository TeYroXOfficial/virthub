@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Domain\Billing\Cycle')

@section('title', $service->name)

@php
    $act = fn (string $action, string $label, string $class = 'btn', ?string $confirm = null) => [$action, $label, $class, $confirm];
    $actions = array_filter([
        $service->status === 'pending' ? $act('activate', __('Uruchom bez płatności'), 'btn', __('Utworzyć usługę bez zapłaty?')) : null,
        $service->status === 'active' ? $act('suspend', __('Zawieś'), 'btn') : null,
        $service->status === 'suspended' ? $act('unsuspend', __('Odwieś'), 'btn btn-primary') : null,
        $service->isLive() && ! $service->metered() && ! $service->cancel_at_period_end ? $act('cancel_end', __('Usuń z końcem okresu'), 'btn') : null,
        $service->isLive() && $service->cancel_at_period_end ? $act('resume', __('Cofnij rezygnację'), 'btn') : null,
        in_array($service->status, ['pending', 'active', 'suspended'], true) ? $act('terminate', __('Usuń teraz'), 'btn btn-danger', __('Usunąć usługę :name razem z maszyną/aplikacją i danymi?', ['name' => $service->name])) : null,
    ]);
@endphp

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.billing.services') }}" style="font-size:13px">{{ __('← Usługi klientów') }}</a>
            <h1>{{ $service->name }}</h1>
            <div class="meta-line">
                <span class="pill {{ $service->statusTone() }}">{{ $service->statusLabel() }}</span>
                @if ($service->suspend_reason) <span class="sep">·</span><span>{{ $service->suspend_reason === 'unpaid' ? __('brak płatności') : __('decyzja administratora') }}</span> @endif
                <span class="sep">·</span>
                @if ($service->user) <a href="{{ route('panel.admin.billing.customer', $service->user) }}">{{ $service->user->email }}</a> @endif
            </div>
        </div>
        <div class="actions">
            @foreach ($actions as [$action, $label, $class, $confirm])
                <form method="POST" action="{{ route('panel.admin.billing.service.action', $service) }}" style="margin:0" @if ($confirm) data-confirm="{{ $confirm }}" @endif>
                    @csrf <input type="hidden" name="action" value="{{ $action }}">
                    <button class="{{ $class }}" type="submit">{{ $label }}</button>
                </form>
            @endforeach
        </div>
    </div>
    @error('service') <div class="alert alert-error">{{ $message }}</div> @enderror
    @if ($service->last_error) <div class="alert alert-warning">{{ __('Ostatni błąd: :error', ['error' => $service->last_error]) }}</div> @endif

    <div class="grid grid-2">
        <div class="card">
            <dl class="kv">
                <dt>{{ __('Produkt') }}</dt><dd>@if ($service->product) <a href="{{ route('panel.admin.billing.products.edit', $service->product) }}">{{ $service->product->name }}</a> @else — @endif</dd>
                <dt>{{ __('Cykl') }}</dt><dd>{{ Cycle::label($service->cycle) }}</dd>
                <dt>{{ __('Zasób') }}</dt>
                <dd>
                    @if ($service->server) <a href="{{ route('panel.servers.show', $service->server) }}">{{ $service->server->hostname }}</a>
                    @elseif ($service->appServer) <a href="{{ route('panel.apps.show', $service->appServer) }}">{{ $service->appServer->name }}</a>
                    @else — @endif
                </dd>
                <dt>{{ __('Konfiguracja') }}</dt><dd class="mono" style="font-size:12px">{{ json_encode(collect($service->config ?? [])->except('ssh_keys'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</dd>
                <dt>{{ __('Zamówiona') }}</dt><dd>{{ $service->created_at->format('d.m.Y H:i') }}</dd>
                @if ($service->terminated_at) <dt>{{ __('Zakończona') }}</dt><dd>{{ $service->terminated_at->format('d.m.Y H:i') }}</dd> @endif
            </dl>
        </div>
        <div class="card">
            <h3 class="card-title">{{ __('Rozliczenie') }}</h3>
            <form method="POST" action="{{ route('panel.admin.billing.service.update', $service) }}">
                @csrf @method('PUT')
                <div class="field"><label for="v-name">{{ __('Nazwa') }}</label><input id="v-name" name="name" required maxlength="150" value="{{ old('name', $service->name) }}"></div>
                <div class="grid grid-2">
                    <div class="field"><label for="v-amount">{{ __('Cena za cykl') }}</label><input id="v-amount" name="amount" inputmode="decimal" required value="{{ old('amount', Money::input($service->amount)) }}">
                        @error('amount') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror</div>
                    <div class="field"><label for="v-due">{{ $service->metered() ? __('Następne naliczenie') : __('Opłacone do') }}</label><input id="v-due" type="datetime-local" name="next_due_at" value="{{ old('next_due_at', $service->next_due_at?->format('Y-m-d\TH:i')) }}"></div>
                </div>
                <button class="btn" type="submit">{{ __('Zapisz') }}</button>
            </form>
        </div>
    </div>

    @php $invoices = $service->invoiceItems->pluck('invoice')->filter()->unique('id')->sortByDesc('id'); @endphp
    @if ($invoices->isNotEmpty())
        <div class="card flush" style="margin-top:16px">
            <div class="dash-head"><h3 class="card-title" style="margin:0">{{ __('Faktury') }}</h3></div>
            @include('panel.billing._invoice-table', ['invoices' => $invoices, 'admin' => true])
        </div>
    @endif
@endsection
