@extends('layouts.panel')

@section('title', __('Dziennik zdarzeń'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Dziennik zdarzeń') }}</h1>
            <p class="lede">{{ __('Kto, co i kiedy zmienił w panelu — logowania, zamówienia, zmiany ustawień i działania personelu.') }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('panel.admin.logs') }}" class="card filter-bar">
        <div class="field" style="margin:0">
            <label for="l-cat">{{ __('Rodzaj') }}</label>
            <select id="l-cat" name="category" onchange="this.form.submit()">
                <option value="">{{ __('wszystkie') }}</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}" @selected($cat === $category)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
        <div class="field" style="margin:0; flex:1">
            <label for="l-q">{{ __('Szukaj') }}</label>
            <input id="l-q" name="q" type="search" value="{{ $term }}" placeholder="{{ __('zdarzenie, e-mail albo adres IP') }}">
        </div>
        <button class="btn" type="submit"><x-icon name="search" :size="15"/> {{ __('Filtruj') }}</button>
    </form>

    <div class="card flush">
        @include('panel.admin._log-table', ['logs' => $logs])
    </div>

    {{ $logs->links() }}
@endsection
