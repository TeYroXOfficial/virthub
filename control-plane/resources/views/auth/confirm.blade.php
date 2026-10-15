@extends('layouts.panel')

@section('title', __('Potwierdź tożsamość'))

@section('content')
    <div style="max-width:440px; margin:40px auto">
        <h1>{{ __('Potwierdź tożsamość') }}</h1>
        <p class="lede">{{ __('Ta operacja jest wrażliwa. Potwierdzenie obowiązuje przez :minutes min — potem panel zapyta ponownie.', ['minutes' => $minutes]) }}</p>
        <div class="card">
            <form method="POST" action="{{ route('confirm') }}">
                @csrf
                <div class="field">
                    <label for="password">{{ __('Hasło') }}</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" autofocus>
                    @error('password') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                @if ($totp)
                    <div class="field">
                        <label for="code">{{ __('albo kod z aplikacji') }}</label>
                        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="32" placeholder="123456">
                        @error('code') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                    </div>
                @endif
                <button class="btn btn-primary" type="submit" style="width:100%">{{ __('Potwierdź') }}</button>
            </form>
            @if ($passkeys)
                @include('auth._passkey-button', ['mode' => 'verify', 'options' => route('confirm.passkey.options'), 'finish' => route('confirm.passkey'), 'label' => __('Potwierdź kluczem dostępu')])
            @endif
        </div>
        <p style="margin-top:12px"><a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('panel.dashboard') }}">{{ __('Anuluj') }}</a></p>
    </div>
@endsection
