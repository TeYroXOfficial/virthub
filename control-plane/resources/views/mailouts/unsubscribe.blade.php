@extends('layouts.panel')

@section('title', __('Wypisanie z wiadomości'))

@section('content')
    <div class="card" style="max-width:480px; margin:40px auto">
        <h3 class="card-title"><x-icon name="mail" :size="16"/> {{ config('virthub.brand') }}</h3>
        @if ($done)
            <p>{{ __('Adres :email nie będzie już dostawać ogłoszeń i newslettera. Ważne wiadomości o Twoich usługach (faktury, awarie) nadal będą przychodzić.', ['email' => $user->email]) }}</p>
            <p class="hint">{{ __('Zmienisz to w Moje konto w panelu.') }}</p>
        @else
            <p>{{ __('Wypisać adres :email z ogłoszeń i newslettera?', ['email' => $user->email]) }}</p>
            <form method="POST" action="{{ request()->fullUrl() }}">@csrf
                <button class="btn btn-primary" type="submit">{{ __('Wypisz mnie') }}</button></form>
        @endif
    </div>
@endsection
