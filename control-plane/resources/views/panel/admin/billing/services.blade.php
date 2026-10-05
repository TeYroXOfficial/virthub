@extends('layouts.panel')
@use('App\Domain\Billing\Money')
@use('App\Domain\Billing\Cycle')

@section('title', __('Usługi klientów'))

@section('content')
    <div class="page-header">
        <div><h1>{{ __('Usługi klientów') }}</h1><p class="lede">{{ __('Wszystko, co klienci zamówili przez sklep.') }}</p></div>
    </div>

    <form class="card filter-bar" method="GET" style="margin-bottom:16px">
        <div class="field" style="margin:0"><label for="s-q">{{ __('Szukaj') }}</label><input id="s-q" type="search" name="q" value="{{ $q }}" placeholder="{{ __('nazwa, e-mail') }}"></div>
        <div class="field" style="margin:0"><label for="s-st">{{ __('Stan') }}</label>
            <select id="s-st" name="status">
                <option value="">{{ __('Wszystkie') }}</option>
                @foreach (['pending' => __('oczekuje na płatność'), 'active' => __('aktywna'), 'suspended' => __('zawieszona'), 'terminated' => __('usunięta'), 'cancelled' => __('anulowana')] as $k => $label)
                    <option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>
                @endforeach
            </select></div>
        <button class="btn" type="submit"><x-icon name="search" :size="15"/> {{ __('Filtruj') }}</button>
    </form>

    <div class="card flush">
        @if ($services->isEmpty())
            <p class="empty-note">{{ __('Brak usług.') }}</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th>{{ __('Usługa') }}</th><th>{{ __('Klient') }}</th><th>{{ __('Cena') }}</th><th>{{ __('Stan') }}</th><th>{{ __('Termin') }}</th></tr></thead>
                    <tbody>
                    @foreach ($services as $service)
                        <tr>
                            <td class="mono muted">{{ $service->id }}</td>
                            <td><a href="{{ route('panel.admin.billing.service', $service) }}" style="font-weight:600">{{ $service->name }}</a></td>
                            <td>@if ($service->user) <a href="{{ route('panel.admin.billing.customer', $service->user) }}">{{ $service->user->email }}</a> @else — @endif</td>
                            <td class="nowrap">{{ Money::format($service->amount) }} <span class="muted">{{ $service->periodLabel() }}</span></td>
                            <td><span class="pill {{ $service->statusTone() }}">{{ $service->statusLabel() }}</span></td>
                            <td class="muted nowrap">{{ $service->next_due_at?->format('d.m.Y H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    {{ $services->links() }}
@endsection
