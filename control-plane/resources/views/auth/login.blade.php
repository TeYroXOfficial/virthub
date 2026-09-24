@extends('layouts.panel')

@section('title', 'Logowanie')

@section('content')
    <div>
        <div class="brand" style="justify-content:center; padding-bottom:8px; font-size:20px">
            <span class="brand-mark"><x-icon name="servers" :size="16"/></span>
            {{ config('virthub.brand') }}
        </div>
        <p class="lede" style="text-align:center; margin:0 auto 24px">Zaloguj się, aby zarządzać swoimi serwerami.</p>

        <div class="card">
            <form method="POST" action="{{ route('login') }}">
                @csrf

                <div class="field">
                    <label for="email">Adres e-mail</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}"
                           required autofocus autocomplete="username">
                </div>

                <div class="field">
                    <label for="password">Hasło</label>
                    <input id="password" name="password" type="password"
                           required autocomplete="current-password">
                </div>

                <div class="field">
                    <label style="font-weight:400; display:flex; gap:8px; align-items:center;">
                        <input type="checkbox" name="remember" value="1" style="width:auto">
                        Zapamiętaj mnie na tym urządzeniu
                    </label>
                </div>

                <button class="btn btn-primary" type="submit" style="width:100%">Zaloguj się</button>
            </form>
        </div>
    </div>
@endsection
