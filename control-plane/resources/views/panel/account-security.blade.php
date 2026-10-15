@extends('layouts.panel')

@section('title', __('Bezpieczeństwo konta'))

@section('content')
    <div class="page-header">
        <div>
            <h1>{{ __('Bezpieczeństwo konta') }}</h1>
            <p class="lede">{{ __('Weryfikacja dwuetapowa, zalogowane urządzenia, tokeny API i adresy IP, z których wolno używać konta.') }}</p>
        </div>
        <div class="btn-row"><a class="btn" href="{{ route('panel.account') }}">{{ __('Moje konto') }}</a></div>
    </div>

    @if ($mustEnable)
        <div class="alert alert-error" role="alert"><div>{{ $user->role === \App\Models\User::ROLE_CUSTOMER
            ? __('Administrator wymaga weryfikacji dwuetapowej — dodaj kod z aplikacji albo klucz dostępu, aby korzystać z panelu.')
            : __('Administrator wymaga weryfikacji dwuetapowej dla kont personelu — włącz ją, aby korzystać z administracji.') }}</div></div>
    @endif

    @if (session('recovery_codes'))
        <div class="card" style="margin-bottom:16px; border-color:var(--warning)">
            <h3 class="card-title"><x-icon name="lock" :size="16"/> {{ __('Kody zapasowe') }}</h3>
            <p class="muted">{{ __('Zapisz je w bezpiecznym miejscu. Każdy kod działa raz — gdy stracisz telefon, zalogujesz się nimi zamiast kodem z aplikacji. Nie zobaczysz ich ponownie.') }}</p>
            <pre class="secret" style="white-space:pre-wrap; columns:2; max-width:420px">@foreach (session('recovery_codes') as $code){{ $code }}
@endforeach</pre>
        </div>
    @endif

    @if (session('new_token'))
        <div class="card" style="margin-bottom:16px; border-color:var(--warning)">
            <h3 class="card-title"><x-icon name="key" :size="16"/> {{ __('Nowy token API') }}</h3>
            <p class="muted">{{ __('Skopiuj go teraz — panel przechowuje tylko jego skrót.') }}</p>
            <input type="text" readonly value="{{ session('new_token') }}" onclick="this.select()" aria-label="{{ __('Token API') }}" style="font-family:var(--mono, monospace)">
            <p class="hint">{{ __('Użycie: nagłówek Authorization: Bearer <token>, adres :url', ['url' => url('/api/v1')]) }}</p>
        </div>
    @endif

    <div class="grid grid-2">
        <div class="card">
            <h3 class="card-title"><x-icon name="lock" :size="16"/> {{ __('Weryfikacja dwuetapowa (2FA)') }}
                <span class="pill {{ $twoFactorEnabled ? 'ok' : 'neutral' }}">{{ $twoFactorEnabled ? __('włączona') : __('wyłączona') }}</span></h3>
            @if ($twoFactorEnabled)
                <p class="muted">{{ __('Przy logowaniu panel prosi o kod z aplikacji uwierzytelniającej.') }}</p>
                <p class="hint">{{ trans_choice('Pozostał :count kod zapasowy.|Pozostały :count kody zapasowe.|Pozostało :count kodów zapasowych.', $recoveryLeft) }}</p>
                <form method="POST" action="{{ route('panel.account.security.2fa.recovery') }}" style="margin-bottom:14px">
                    @csrf
                    <div class="field"><label for="rc-password">{{ __('Obecne hasło') }}</label>
                        <input id="rc-password" name="current_password" type="password" required autocomplete="current-password"></div>
                    <button class="btn" type="submit">{{ __('Nowe kody zapasowe') }}</button>
                </form>
                <form method="POST" action="{{ route('panel.account.security.2fa.disable') }}">
                    @csrf @method('DELETE')
                    <div class="grid grid-2">
                        <div class="field"><label for="d-password">{{ __('Obecne hasło') }}</label>
                            <input id="d-password" name="current_password" type="password" required autocomplete="current-password"></div>
                        <div class="field"><label for="d-code">{{ __('Kod z aplikacji') }}</label>
                            <input id="d-code" name="code" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="32"></div>
                    </div>
                    <button class="btn btn-danger" type="submit">{{ __('Wyłącz 2FA') }}</button>
                </form>
            @elseif ($twoFactorPending)
                <p class="muted">{{ __('Zeskanuj kod w aplikacji (Google Authenticator, Authy, 1Password, Bitwarden…) i wpisz wygenerowany kod.') }}</p>
                <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap">
                    <div style="background:#fff; padding:8px; border-radius:8px; line-height:0">{!! $qr !!}</div>
                    <div style="flex:1; min-width:200px">
                        <p class="hint">{{ __('Albo wpisz klucz ręcznie:') }}</p>
                        <code style="word-break:break-all">{{ trim(chunk_split($user->two_factor_secret, 4, ' ')) }}</code>
                        <form method="POST" action="{{ route('panel.account.security.2fa.confirm') }}" style="margin-top:12px">
                            @csrf
                            <div class="field"><label for="c-code">{{ __('Kod z aplikacji') }}</label>
                                <input id="c-code" name="code" type="text" required inputmode="numeric" autocomplete="one-time-code" maxlength="16" autofocus></div>
                            <div class="btn-row">
                                <button class="btn btn-primary" type="submit">{{ __('Potwierdź i włącz') }}</button>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('panel.account.security.2fa.cancel') }}" style="margin-top:6px">
                            @csrf <button class="btn btn-sm" type="submit">{{ __('Anuluj') }}</button>
                        </form>
                    </div>
                </div>
            @else
                <p class="muted">{{ __('Drugi składnik logowania: nawet znając hasło, nikt nie zaloguje się bez kodu z Twojego telefonu.') }}</p>
                <form method="POST" action="{{ route('panel.account.security.2fa.enable') }}">
                    @csrf
                    <div class="field"><label for="e-password">{{ __('Obecne hasło') }}</label>
                        <input id="e-password" name="current_password" type="password" required autocomplete="current-password"></div>
                    <button class="btn btn-primary" type="submit">{{ __('Włącz 2FA') }}</button>
                </form>
            @endif
            @foreach ($errors->getBag('twoFactor')->all() as $message)
                <div class="hint" style="color:var(--critical)">{{ $message }}</div>
            @endforeach
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="network" :size="16"/> {{ __('Dozwolone adresy IP') }}</h3>
            <p class="muted">{{ __('Gdy lista nie jest pusta, panel i API przyjmują to konto tylko z tych adresów lub podsieci (np. 203.0.113.10, 198.51.100.0/24, 2001:db8::/48).') }}</p>
            <form method="POST" action="{{ route('panel.account.security.ips') }}">
                @csrf @method('PUT')
                <div class="field"><label for="ips">{{ __('Adresy i podsieci (po jednym w linii)') }}</label>
                    <textarea id="ips" name="allowed_ips" rows="4" placeholder="{{ $currentIp }}">{{ old('allowed_ips', $user->allowed_ips) }}</textarea>
                    <div class="hint">{{ __('Twój obecny adres: :ip', ['ip' => $currentIp]) }}</div></div>
                <div class="field"><label for="ips-password">{{ __('Obecne hasło') }}</label>
                    <input id="ips-password" name="current_password" type="password" required autocomplete="current-password"></div>
                @foreach ($errors->getBag('ips')->all() as $message)
                    <div class="hint" style="color:var(--critical)">{{ $message }}</div>
                @endforeach
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
        </div>
    </div>

    <div class="grid grid-2 dash-section" style="margin-top:16px">
        <div class="card" id="passkeys">
            <h3 class="card-title"><x-icon name="key" :size="16"/> {{ __('Klucze dostępu (passkeys)') }}
                <span class="pill {{ $passkeys->isNotEmpty() ? 'ok' : 'neutral' }}">{{ $passkeys->count() }}</span></h3>
            <p class="muted">{{ __('Logowanie odciskiem palca, twarzą, PIN-em urządzenia albo kluczem sprzętowym (YubiKey, Titan). Klucz zastępuje hasło i kod z aplikacji — nie da się go wyłudzić na fałszywej stronie.') }}</p>
            @if ($passkeys->isNotEmpty())
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>{{ __('Nazwa') }}</th><th>{{ __('Dodano') }}</th><th>{{ __('Ostatnie użycie') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($passkeys as $passkey)
                            <tr>
                                <td><strong>{{ $passkey->name }}</strong></td>
                                <td>{{ $passkey->created_at->format('d.m.Y') }}</td>
                                <td>{{ $passkey->last_used_at?->diffForHumans() ?? __('nigdy') }}</td>
                                <td style="text-align:right">
                                    <form method="POST" action="{{ route('panel.account.security.passkeys.destroy', $passkey) }}" style="margin:0" data-confirm="{{ __('Usunąć klucz :name?', ['name' => $passkey->name]) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @error('passkeys') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
            <div class="btn-row" style="margin-top:12px; align-items:flex-end">
                <div class="field" style="margin:0; flex:1; min-width:180px"><label for="passkey-name">{{ __('Nazwa nowego klucza') }}</label>
                    <input id="passkey-name" maxlength="80" placeholder="{{ __('np. Laptop, YubiKey') }}"></div>
                <button class="btn btn-primary" type="button" data-webauthn="register"
                        data-options="{{ route('panel.account.security.passkeys.options') }}" data-finish="{{ route('panel.account.security.passkeys.store') }}"
                        data-unsupported="{{ __('Ta przeglądarka nie obsługuje kluczy dostępu.') }}" data-cancelled="{{ __('Anulowano — spróbuj jeszcze raz.') }}">
                    <x-icon name="plus" :size="15"/> {{ __('Dodaj klucz dostępu') }}</button>
            </div>
            <div class="hint" id="webauthn-error" role="alert" style="color:var(--critical)" hidden></div>
            <p class="hint">{{ __('Panel może poprosić o potwierdzenie tożsamości przed dodaniem klucza.') }}</p>
            @push('scripts')
                <script src="{{ asset('js/webauthn.js') }}?v={{ @filemtime(public_path('js/webauthn.js')) }}"></script>
            @endpush
        </div>

        <div class="card">
            <h3 class="card-title"><x-icon name="lock" :size="16"/> {{ __('Przypięcie sesji') }}
                <span class="pill {{ $user->session_lock ? 'ok' : 'neutral' }}">{{ $user->session_lock ? __('włączone') : __('wyłączone') }}</span></h3>
            <p class="muted">{{ __('Sesja działa tylko z adresu IP i przeglądarki, z których się zalogowano. Skradzione ciasteczko sesji nie zadziała na innym komputerze, ale zmiana sieci (np. telefon w podróży) wymaga ponownego logowania.') }}</p>
            <form method="POST" action="{{ route('panel.account.security.session-lock') }}">
                @csrf @method('PUT')
                <input type="hidden" name="session_lock" value="{{ $user->session_lock ? 0 : 1 }}">
                <button class="btn {{ $user->session_lock ? '' : 'btn-primary' }}" type="submit">{{ $user->session_lock ? __('Wyłącz przypięcie') : __('Przypnij sesje do IP i przeglądarki') }}</button>
            </form>
            <p class="hint">{{ __('Operacje wrażliwe (usunięcie i reinstalacja maszyny, tokeny API, klucze dostępu, zarządzanie kontami) wymagają ponownego potwierdzenia tożsamości co :minutes min.', ['minutes' => \App\Domain\Security\StepUp::minutes()]) }}</p>
        </div>
    </div>

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0"><x-icon name="logout" :size="16"/> {{ __('Zalogowane urządzenia') }}</h3></div>
        @if ($sessions->isNotEmpty())
            <div class="table-wrap">
                <table>
                    <thead><tr><th>{{ __('Urządzenie') }}</th><th>{{ __('Adres IP') }}</th><th>{{ __('Ostatnia aktywność') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($sessions as $session)
                        <tr>
                            <td>{{ $session->device }} @if ($session->current) <span class="pill ok">{{ __('to urządzenie') }}</span> @endif</td>
                            <td><code>{{ $session->ip }}</code></td>
                            <td>{{ $session->last_activity->diffForHumans() }}</td>
                            <td style="text-align:right">
                                @unless ($session->current)
                                    <form method="POST" action="{{ route('panel.account.security.sessions.destroy', $session->key) }}" style="margin:0">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm" type="submit">{{ __('Wyloguj') }}</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div style="padding:16px">
            <form method="POST" action="{{ route('panel.account.logout-others') }}" class="btn-row" style="align-items:flex-end">
                @csrf
                <div class="field" style="margin:0"><label for="o-current">{{ __('Obecne hasło') }}</label>
                    <input id="o-current" name="current_password" type="password" required autocomplete="current-password"></div>
                <button class="btn" type="submit">{{ __('Wyloguj wszystkie inne urządzenia') }}</button>
            </form>
            @error('current_password', 'devices') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
        </div>
    </div>

    <div class="card flush dash-section">
        <div class="dash-head"><h3 class="card-title" style="margin:0"><x-icon name="key" :size="16"/> {{ __('Tokeny API') }}</h3>
            <a class="btn btn-sm" href="{{ route('api-docs') }}">{{ __('Dokumentacja API') }}</a></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>{{ __('Nazwa') }}</th><th>{{ __('Zakres') }}</th><th>{{ __('Limit') }}</th><th>{{ __('Ostatnie użycie') }}</th><th>{{ __('Wygasa') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($tokens as $token)
                    <tr>
                        <td><strong>{{ $token->name }}</strong></td>
                        <td>{{ \App\Http\Controllers\Web\AccountSecurityController::scopeLabels($token->abilities ?? []) }}</td>
                        <td class="num">{{ __(':count / min', ['count' => $token->rate_limit ?: $defaultRateLimit]) }}</td>
                        <td>{{ $token->last_used_at?->diffForHumans() ?? __('nigdy') }}</td>
                        <td>{{ $token->expires_at?->format('d.m.Y') ?? __('bez terminu') }}</td>
                        <td style="text-align:right">
                            <form method="POST" action="{{ route('panel.account.security.tokens.destroy', $token->id) }}" style="margin:0" data-confirm="{{ __('Usunąć token? Integracje, które go używają, przestaną działać.') }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger" type="submit">{{ __('Usuń') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('Brak tokenów.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <form method="POST" action="{{ route('panel.account.security.tokens.store') }}" style="padding:16px">
            @csrf
            <div class="grid grid-3">
                <div class="field"><label for="t-name">{{ __('Nazwa tokenu') }}</label>
                    <input id="t-name" name="name" required maxlength="100" value="{{ old('name') }}" placeholder="{{ __('np. skrypt kopii') }}"></div>
                <div class="field"><label for="t-exp">{{ __('Ważność (dni, puste = bez terminu)') }}</label>
                    <input id="t-exp" name="expires_days" type="number" min="1" max="3650" value="{{ old('expires_days') }}"></div>
                <div class="field"><label for="t-rate">{{ __('Limit zapytań na minutę') }}</label>
                    <input id="t-rate" name="rate_limit" type="number" min="1" max="10000" value="{{ old('rate_limit') }}" placeholder="{{ $defaultRateLimit }}"></div>
            </div>
            <fieldset class="field" style="border:0; padding:0; margin:0 0 12px">
                <legend style="font-weight:600; margin-bottom:6px">{{ __('Zakres') }}</legend>
                @foreach ($scopes as $scope => $label)
                    <label style="font-weight:400; display:flex; gap:8px; align-items:center">
                        <input type="checkbox" name="scopes[]" value="{{ $scope }}" style="width:auto" @checked(in_array($scope, old('scopes', ['servers:read']), true))>
                        <span><code>{{ $scope }}</code> — {{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>
            @foreach ($errors->getBag('tokens')->all() as $message)
                <div class="hint" style="color:var(--critical)">{{ $message }}</div>
            @endforeach
            <button class="btn btn-primary" type="submit">{{ __('Utwórz token') }}</button>
        </form>
    </div>
@endsection
