@extends('layouts.panel')
@use('App\Domain\Billing\Money')

@section('title', __('Portfele'))

@section('content')
    <div class="page-header">
        <div><h1>{{ __('Portfele') }}</h1><p class="lede">{{ __('Salda klientów, historia operacji i ręczne korekty.') }}</p></div>
    </div>

    <form class="card filter-bar" method="GET" style="margin-bottom:16px">
        <div class="field" style="margin:0"><label for="u-q">{{ __('Szukaj') }}</label><input id="u-q" type="search" name="q" value="{{ $q }}" placeholder="{{ __('e-mail, nazwa') }}"></div>
        <button class="btn" type="submit"><x-icon name="search" :size="15"/> {{ __('Filtruj') }}</button>
    </form>

    <div class="card flush">
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Klient') }}</th><th>{{ __('Aktywne usługi') }}</th><th style="text-align:right">{{ __('Saldo') }}</th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td><a href="{{ route('panel.admin.billing.customer', $u) }}" style="font-weight:600">{{ $u->email }}</a> @if ($u->name) <span class="muted">{{ $u->name }}</span> @endif</td>
                        <td class="num">{{ $u->live_count }}</td>
                        <td class="num nowrap" @if ($u->wallet_balance < 0) style="color:var(--critical)" @endif>{{ Money::format($u->wallet_balance) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    {{ $users->links() }}
@endsection
