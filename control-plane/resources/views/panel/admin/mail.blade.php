@extends('layouts.panel')

@section('title', __('Poczta (SMTP)'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Poczta (SMTP)') }}</h1>
            <p class="lede">{{ __('Serwer, przez który panel wysyła e-maile — np. link „Nie pamiętasz hasła?”.') }}</p>
        </div>
    </div>

    @include('panel.admin._nav')

    @unless ($mail['enabled'])
        <div class="alert alert-warning">
            {{ __('Wysyłka jest wyłączona — wiadomości trafiają tylko do logu panelu i nie dotrą do klientów.') }}
        </div>
    @endunless

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title"><x-icon name="mail" :size="16"/> {{ __('Serwer SMTP') }}</h3>
            <form method="POST" action="{{ route('panel.admin.mail.update') }}">
                @csrf @method('PUT')
                <label class="check-line" style="margin-bottom:12px">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $mail['enabled']))>
                    {{ __('Wysyłaj e-maile przez SMTP') }}
                </label>
                <div class="grid-compact">
                    <div class="field">
                        <label for="m-host">{{ __('Serwer') }}</label>
                        <input id="m-host" name="host" type="text" maxlength="255" value="{{ old('host', $mail['host']) }}" placeholder="smtp.example.com">
                        @error('host') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="m-port">{{ __('Port') }}</label>
                        <input id="m-port" name="port" type="text" inputmode="numeric" value="{{ old('port', $mail['port']) }}">
                        @error('port') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                    </div>
                </div>
                <div class="field">
                    <label for="m-enc">{{ __('Szyfrowanie') }}</label>
                    <select id="m-enc" name="encryption">
                        @foreach ($encryptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('encryption', $mail['encryption']) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid-compact">
                    <div class="field">
                        <label for="m-user">{{ __('Użytkownik') }}</label>
                        <input id="m-user" name="username" type="text" maxlength="255" value="{{ old('username', $mail['username']) }}" autocomplete="off">
                    </div>
                    <div class="field">
                        <label for="m-pass">{{ __('Hasło') }}</label>
                        <input id="m-pass" name="password" type="password" maxlength="255" autocomplete="new-password"
                               placeholder="{{ $mail['has_password'] ? __('zapisane — zostaw puste, żeby nie zmieniać') : '' }}">
                    </div>
                </div>
                <div class="grid-compact">
                    <div class="field">
                        <label for="m-from">{{ __('Adres nadawcy') }}</label>
                        <input id="m-from" name="from_address" type="email" required maxlength="255" value="{{ old('from_address', $mail['from_address']) }}" placeholder="panel@example.com">
                        @error('from_address') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                    </div>
                    <div class="field">
                        <label for="m-name">{{ __('Nazwa nadawcy') }}</label>
                        <input id="m-name" name="from_name" type="text" maxlength="100" value="{{ old('from_name', $mail['from_name']) }}">
                    </div>
                </div>
                <p class="hint">{{ __('Hasło jest zapisywane w bazie w postaci zaszyfrowanej kluczem panelu (APP_KEY). Ustawienia stąd mają pierwszeństwo przed MAIL_* w pliku .env.') }}</p>
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="upload" :size="16"/> {{ __('Testowy e-mail') }}</h3>
            <p class="muted">{{ __('Zapisz ustawienia, a potem wyślij wiadomość próbną. Jeśli się nie uda, zobaczysz tu błąd serwera pocztowego.') }}</p>
            <form method="POST" action="{{ route('panel.admin.mail.test') }}">
                @csrf
                <div class="field">
                    <label for="m-to">{{ __('Wyślij na adres') }}</label>
                    <input id="m-to" name="to" type="email" required value="{{ old('to', auth()->user()->email) }}">
                    @error('to') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <button class="btn" type="submit">{{ __('Wyślij test') }}</button>
            </form>
        </div>
    </div>
@endsection
