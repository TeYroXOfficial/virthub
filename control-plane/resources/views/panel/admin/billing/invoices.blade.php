@extends('layouts.panel')

@section('title', __('Faktury'))

@section('content')
    <div class="page-header">
        <div><h1>{{ __('Faktury') }}</h1><p class="lede">{{ __('Faktury za usługi i doładowania portfeli.') }}</p></div>
    </div>

    <form class="card filter-bar" method="GET" style="margin-bottom:16px">
        <div class="field" style="margin:0"><label for="i-q">{{ __('Szukaj') }}</label><input id="i-q" type="search" name="q" value="{{ $q }}" placeholder="{{ __('numer, e-mail') }}"></div>
        <div class="field" style="margin:0"><label for="i-st">{{ __('Stan') }}</label>
            <select id="i-st" name="status">
                <option value="">{{ __('Wszystkie') }}</option>
                @foreach (['unpaid' => __('do zapłaty'), 'overdue' => __('po terminie'), 'paid' => __('opłacona'), 'cancelled' => __('anulowana'), 'refunded' => __('zwrócona')] as $k => $label)
                    <option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>
                @endforeach
            </select></div>
        <button class="btn" type="submit"><x-icon name="search" :size="15"/> {{ __('Filtruj') }}</button>
    </form>

    <div class="card flush">
        @if ($invoices->isEmpty())
            <p class="empty-note">{{ __('Brak faktur.') }}</p>
        @else
            @include('panel.billing._invoice-table', ['invoices' => $invoices, 'admin' => true])
        @endif
    </div>
    {{ $invoices->links() }}
@endsection
