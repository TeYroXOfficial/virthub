@extends('layouts.panel')

@section('title', __('Bramki płatności'))

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.billing') }}" style="font-size:13px">{{ __('← Billing') }}</a>
            <h1>{{ __('Bramki płatności') }}</h1>
            <p class="lede">{{ __('Klienci płacą faktury i doładowują portfel kartą (Stripe) albo przez PayPal. Płatność zalicza się po potwierdzeniu u dostawcy — przy powrocie klienta albo z webhooka.') }}</p>
        </div>
    </div>
    @error('gateway') <div class="alert alert-error">{{ $message }}</div> @enderror

    <div class="grid grid-2">
        <div class="card">
            <div class="dash-head" style="padding:0 0 12px">
                <h3 class="card-title" style="margin:0">Stripe <span class="pill {{ $stripe['enabled'] && $stripe['has_secret'] ? 'ok' : 'neutral' }}">{{ $stripe['enabled'] && $stripe['has_secret'] ? __('włączony') : __('wyłączony') }}</span></h3>
                @if ($stripe['has_secret'])
                    <form method="POST" action="{{ route('panel.admin.billing.gateways.test', 'stripe') }}" style="margin:0">@csrf <button class="btn btn-sm" type="submit">{{ __('Sprawdź połączenie') }}</button></form>
                @endif
            </div>
            <form method="POST" action="{{ route('panel.admin.billing.gateways.update', 'stripe') }}" autocomplete="off">
                @csrf @method('PUT')
                <label class="check-line"><input type="checkbox" name="enabled" value="1" @checked($stripe['enabled'])> {{ __('Przyjmuj płatności przez Stripe') }}</label>
                <div class="field">
                    <label for="st-key">{{ __('Klucz tajny (Secret key)') }}</label>
                    <input id="st-key" type="password" name="secret_key" placeholder="{{ $stripe['has_secret'] ? __('zapisany — wpisz, żeby zmienić') : 'sk_live_…' }}">
                    @error('secret_key') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                    <div class="hint">{{ __('Stripe → Developers → API keys. Możesz użyć klucza ograniczonego (rk_…) z uprawnieniem do Checkout Sessions.') }}</div>
                </div>
                <div class="field">
                    <label for="st-wh">{{ __('Sekret webhooka (Signing secret)') }}</label>
                    <input id="st-wh" type="password" name="webhook_secret" placeholder="{{ $stripe['has_webhook'] ? __('zapisany — wpisz, żeby zmienić') : 'whsec_…' }}">
                    @error('webhook_secret') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label>{{ __('Adres webhooka') }}</label>
                    <code class="copyable mono" data-copy="{{ $stripe['webhook_url'] }}">{{ $stripe['webhook_url'] }}</code>
                    <div class="hint">{{ __('Stripe → Developers → Webhooks → Add endpoint. Zdarzenia: checkout.session.completed i checkout.session.async_payment_succeeded.') }}</div>
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
        </div>

        <div class="card">
            <div class="dash-head" style="padding:0 0 12px">
                <h3 class="card-title" style="margin:0">PayPal <span class="pill {{ $paypal['enabled'] && $paypal['has_secret'] ? 'ok' : 'neutral' }}">{{ $paypal['enabled'] && $paypal['has_secret'] ? __('włączony') : __('wyłączony') }}</span></h3>
                @if ($paypal['has_secret'])
                    <form method="POST" action="{{ route('panel.admin.billing.gateways.test', 'paypal') }}" style="margin:0">@csrf <button class="btn btn-sm" type="submit">{{ __('Sprawdź połączenie') }}</button></form>
                @endif
            </div>
            <form method="POST" action="{{ route('panel.admin.billing.gateways.update', 'paypal') }}" autocomplete="off">
                @csrf @method('PUT')
                <label class="check-line"><input type="checkbox" name="enabled" value="1" @checked($paypal['enabled'])> {{ __('Przyjmuj płatności przez PayPal') }}</label>
                <div class="field">
                    <label for="pp-mode">{{ __('Tryb') }}</label>
                    <select id="pp-mode" name="mode">
                        <option value="sandbox" @selected($paypal['mode'] === 'sandbox')>{{ __('Sandbox (testy)') }}</option>
                        <option value="live" @selected($paypal['mode'] === 'live')>{{ __('Produkcja') }}</option>
                    </select>
                </div>
                <div class="field">
                    <label for="pp-id">{{ __('Identyfikator klienta (Client ID)') }}</label>
                    <input id="pp-id" type="text" name="client_id" value="{{ old('client_id', $paypal['client_id']) }}">
                    @error('client_id') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="pp-secret">{{ __('Klucz tajny (Secret)') }}</label>
                    <input id="pp-secret" type="password" name="client_secret" placeholder="{{ $paypal['has_secret'] ? __('zapisany — wpisz, żeby zmienić') : '' }}">
                    <div class="hint">{{ __('developer.paypal.com → Apps & Credentials → aplikacja REST.') }}</div>
                </div>
                <div class="field">
                    <label for="pp-wh">{{ __('Webhook ID') }}</label>
                    <input id="pp-wh" type="text" name="webhook_id" value="{{ old('webhook_id', $paypal['webhook_id']) }}">
                    @error('webhook_id') <p class="hint" style="color:var(--critical)">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label>{{ __('Adres webhooka') }}</label>
                    <code class="copyable mono" data-copy="{{ $paypal['webhook_url'] }}">{{ $paypal['webhook_url'] }}</code>
                    <div class="hint">{{ __('W aplikacji PayPal → Webhooks → Add webhook. Zdarzenia: Checkout order approved i Payment capture completed. Skopiuj Webhook ID powyżej.') }}</div>
                </div>
                <button class="btn btn-primary" type="submit">{{ __('Zapisz') }}</button>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:16px">
        <h3 class="card-title">{{ __('Jak to działa') }}</h3>
        <ul class="plain-list">
            <li>{{ __('Kwota i waluta płatności muszą zgadzać się z fakturą; nadpłata trafia do portfela klienta.') }}</li>
            <li>{{ __('Ta sama płatność zgłoszona dwa razy (powrót klienta i webhook) liczy się raz.') }}</li>
            <li>{{ __('Zwroty na kartę/PayPal robisz w panelu dostawcy; w panelu VirtHub możesz zwrócić kwotę do portfela klienta.') }}</li>
            <li>{{ __('Klucze są zaszyfrowane kluczem panelu (APP_KEY).') }}</li>
        </ul>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('code[data-copy]').forEach(function (el) {
            el.title = @js(__('Kliknij, żeby skopiować'));
            el.style.cursor = 'pointer';
            el.addEventListener('click', function () { if (navigator.clipboard) navigator.clipboard.writeText(el.dataset.copy); });
        });
    </script>
@endpush
