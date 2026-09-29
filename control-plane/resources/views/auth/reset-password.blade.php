@extends('layouts.panel')

@section('title', __('Nowe hasło'))

@section('content')
    <div>
        <div class="brand" style="justify-content:center; padding-bottom:8px; font-size:20px">
            <span class="brand-mark"><x-icon name="servers" :size="16"/></span>
            {{ config('virthub.brand') }}
        </div>
        <p class="lede" style="text-align:center; margin:0 auto 24px">{{ __('Ustaw nowe hasło do panelu.') }}</p>

        <div class="card">
            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="field">
                    <label for="email">{{ __('Adres e-mail') }}</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username">
                    @error('email') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="password">{{ __('Nowe hasło') }}</label>
                    <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
                    <div class="hint">{{ __('Co najmniej 10 znaków.') }}</div>
                    @error('password') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">{{ __('Powtórz hasło') }}</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                </div>
                <button class="btn btn-primary" type="submit" style="width:100%">{{ __('Ustaw hasło') }}</button>
            </form>
        </div>
    </div>
@endsection
