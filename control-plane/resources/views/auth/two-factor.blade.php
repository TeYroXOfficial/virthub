@extends('layouts.panel')

@section('title', __('Weryfikacja dwuetapowa'))

@section('content')
    <div>
        <div class="brand" style="justify-content:center; padding-bottom:8px; font-size:20px">
            <span class="brand-mark"><x-icon name="servers" :size="16"/></span>
            {{ config('virthub.brand') }}
        </div>
        <p class="lede" style="text-align:center; margin:0 auto 24px">
            @if ($totp)
                {{ __('Wpisz 6-cyfrowy kod z aplikacji uwierzytelniającej albo jeden z kodów zapasowych.') }}
            @else
                {{ __('Potwierdź logowanie kluczem dostępu (passkey, klucz sprzętowy).') }}
            @endif
        </p>

        <div class="card">
            @if ($totp)
                <form method="POST" action="{{ route('login.two-factor') }}">
                    @csrf
                    <div class="field">
                        <label for="code">{{ __('Kod') }}</label>
                        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                               required autofocus maxlength="32" placeholder="123456">
                        @error('code') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                    </div>
                    <button class="btn btn-primary" type="submit" style="width:100%">{{ __('Zaloguj się') }}</button>
                </form>
            @endif
            @if ($passkeys)
                @include('auth._passkey-button', ['mode' => 'verify', 'options' => route('login.two-factor.passkey.options'), 'finish' => route('login.two-factor.passkey'), 'label' => __('Użyj klucza dostępu'), 'separator' => $totp])
            @endif
        </div>
        <p style="text-align:center; margin-top:16px"><a href="{{ route('login') }}">{{ __('Wróć do logowania') }}</a></p>
    </div>
@endsection
