@extends('layouts.panel')

@section('title', __('Nie pamiętasz hasła?'))

@section('content')
    <div>
        <div class="brand" style="justify-content:center; padding-bottom:8px; font-size:20px">
            <span class="brand-mark"><x-icon name="servers" :size="16"/></span>
            {{ config('virthub.brand') }}
        </div>
        <p class="lede" style="text-align:center; margin:0 auto 24px">{{ __('Podaj adres e-mail konta — wyślemy link do ustawienia nowego hasła.') }}</p>

        <div class="card">
            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <div class="field">
                    <label for="email">{{ __('Adres e-mail') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
                    @error('email') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <button class="btn btn-primary" type="submit" style="width:100%">{{ __('Wyślij link') }}</button>
            </form>
        </div>
        <p style="text-align:center; margin-top:16px"><a href="{{ route('login') }}">{{ __('Wróć do logowania') }}</a></p>
    </div>
@endsection
