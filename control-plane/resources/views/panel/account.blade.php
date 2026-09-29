@extends('layouts.panel')

@section('title', __('Moje konto'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Moje konto') }}</h1>
            <p class="lede">{{ __('Dane konta, avatar, hasło i zalogowane urządzenia.') }}</p>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title"><x-icon name="users" :size="16"/> {{ __('Avatar') }}</h3>
            <div style="display:flex; align-items:center; gap:16px">
                @if ($avatar = $user->avatarUrl())
                    <img class="avatar avatar-lg" src="{{ $avatar }}" alt="{{ __('Avatar') }}">
                @else
                    <span class="avatar avatar-lg">{{ mb_substr($user->name ?: $user->email, 0, 1) }}</span>
                @endif
                <div style="flex:1">
                    <form method="POST" action="{{ route('panel.account.avatar') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" required aria-label="{{ __('Plik avatara') }}">
                        <div class="hint">{{ __('PNG, JPG albo WebP, do 2 MB.') }}</div>
                        @error('avatar') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                        <div class="btn-row" style="margin-top:8px">
                            <button class="btn btn-sm btn-primary" type="submit">{{ __('Wgraj') }}</button>
                        </div>
                    </form>
                    @if ($user->avatar_path)
                        <form method="POST" action="{{ route('panel.account.avatar.delete') }}" style="margin-top:6px">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm" type="submit">{{ __('Usuń avatar') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="list" :size="16"/> {{ __('Dane konta') }}</h3>
            <form method="POST" action="{{ route('panel.account.profile') }}">
                @csrf @method('PUT')
                <div class="field">
                    <label for="a-name">{{ __('Nazwa') }}</label>
                    <input id="a-name" name="name" type="text" maxlength="100" required value="{{ old('name', $user->name) }}">
                    @error('name') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="a-email">{{ __('Adres e-mail') }}</label>
                    <input id="a-email" name="email" type="email" required value="{{ old('email', $user->email) }}" autocomplete="username">
                    @error('email') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="a-current">{{ __('Obecne hasło') }} <span class="muted">{{ __('(tylko przy zmianie e-maila)') }}</span></label>
                    <input id="a-current" name="current_password" type="password" autocomplete="current-password">
                    @error('current_password') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="power" :size="16"/> {{ __('Zmiana hasła') }}</h3>
            <form method="POST" action="{{ route('panel.account.password') }}">
                @csrf @method('PUT')
                <div class="field">
                    <label for="p-current">{{ __('Obecne hasło') }}</label>
                    <input id="p-current" name="current_password" type="password" required autocomplete="current-password">
                    @error('current_password', 'password') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="p-new">{{ __('Nowe hasło') }}</label>
                    <input id="p-new" name="password" type="password" required minlength="10" autocomplete="new-password">
                    @error('password', 'password') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <div class="field">
                    <label for="p-confirm">{{ __('Powtórz nowe hasło') }}</label>
                    <input id="p-confirm" name="password_confirmation" type="password" required autocomplete="new-password">
                </div>
                <p class="hint">{{ __('Hasło do panelu jest też hasłem SFTP aplikacji, które nie mają osobnego hasła.') }}</p>
                <button class="btn btn-primary" type="submit">{{ __('Zmień hasło') }}</button>
            </form>
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="logout" :size="16"/> {{ __('Zalogowane urządzenia') }}</h3>
            <p class="muted">{{ __('Wyloguj panel na wszystkich innych komputerach i telefonach — np. gdy logowałeś się na cudzym sprzęcie.') }}</p>
            @if ($user->last_login_at)
                <p class="hint">{{ __('Ostatnie logowanie: :date', ['date' => $user->last_login_at->format('d.m.Y H:i')]) }}</p>
            @endif
            <form method="POST" action="{{ route('panel.account.logout-others') }}">
                @csrf
                <div class="field">
                    <label for="o-current">{{ __('Obecne hasło') }}</label>
                    <input id="o-current" name="current_password" type="password" required autocomplete="current-password">
                    @error('current_password', 'devices') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
                </div>
                <button class="btn" type="submit">{{ __('Wyloguj inne urządzenia') }}</button>
            </form>
        </div>
    </div>
@endsection
