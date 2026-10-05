@extends('layouts.panel')

@section('title', __('Ustawienia billingu'))

@section('content')
    <div class="page-header">
        <div>
            <a class="muted" href="{{ route('panel.admin.billing') }}" style="font-size:13px">{{ __('← Billing') }}</a>
            <h1>{{ __('Ustawienia billingu') }}</h1>
            <p class="lede">{{ __('Waluta, podatek, dane na fakturze i terminy: odnowienia, przypomnienia, zawieszenie i usunięcie za brak płatności.') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('panel.admin.billing.settings.update') }}">
        @csrf @method('PUT')
        <div class="card">
            <div class="setting-row">
                <div class="setting-text">
                    <h3>{{ __('Wbudowany billing') }}</h3>
                    <p class="muted">{{ __('Włączony: klienci zamawiają przez sklep i płacą przed utworzeniem usługi (portfel, faktury, Stripe/PayPal). Wyłączony: panel działa jak dotąd, a rozliczenia może prowadzić zewnętrzny system przez API.') }}</p>
                </div>
                <label class="check-line"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $s['enabled']) === '1')> {{ __('Włączony') }}</label>
            </div>
        </div>

        <div class="grid grid-2" style="margin-top:16px">
            <div class="card">
                <h3 class="card-title">{{ __('Ceny i podatek') }}</h3>
                <div class="grid grid-2">
                    <div class="field"><label for="b-cur">{{ __('Waluta') }}</label><input id="b-cur" name="currency" maxlength="3" required value="{{ old('currency', $s['currency']) }}"></div>
                    <div class="field"><label for="b-tax">{{ __('VAT (%)') }}</label><input id="b-tax" name="tax_rate" inputmode="decimal" required value="{{ old('tax_rate', $s['tax_rate']) }}"></div>
                </div>
                <label class="check-line"><input type="checkbox" name="prices_include_tax" value="1" @checked(old('prices_include_tax', $s['prices_include_tax']) === '1')> {{ __('Ceny w katalogu zawierają VAT (brutto)') }}</label>
                <div class="hint" style="margin-bottom:12px">{{ __('Zmiana waluty nie przelicza istniejących cen ani sald.') }}</div>
                <div class="grid grid-2">
                    <div class="field"><label for="b-min">{{ __('Minimalne doładowanie') }}</label><input id="b-min" name="min_topup" inputmode="decimal" required value="{{ old('min_topup', $s['min_topup']) }}"></div>
                    <div class="field"><label for="b-max">{{ __('Maksymalne doładowanie') }}</label><input id="b-max" name="max_topup" inputmode="decimal" required value="{{ old('max_topup', $s['max_topup']) }}"></div>
                    <div class="field"><label for="b-minbal">{{ __('Minimalne saldo dla usług godzinowych') }}</label><input id="b-minbal" name="min_balance_metered" inputmode="decimal" required value="{{ old('min_balance_metered', $s['min_balance_metered']) }}"></div>
                    <div class="field"><label for="b-low">{{ __('Ostrzeżenie: saldo na mniej niż (godzin)') }}</label><input id="b-low" type="number" name="low_balance_hours" min="0" max="720" required value="{{ old('low_balance_hours', $s['low_balance_hours']) }}"></div>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title">{{ __('Terminy') }}</h3>
                <div class="grid grid-2">
                    <div class="field"><label for="b-due">{{ __('Termin płatności nowej faktury (dni)') }}</label><input id="b-due" type="number" name="due_days" min="0" max="90" required value="{{ old('due_days', $s['due_days']) }}"></div>
                    <div class="field"><label for="b-ren">{{ __('Faktura odnowienia przed końcem okresu (dni)') }}</label><input id="b-ren" type="number" name="renewal_days" min="0" max="60" required value="{{ old('renewal_days', $s['renewal_days']) }}"></div>
                    <div class="field"><label for="b-rem">{{ __('Przypomnienie przed terminem (dni)') }}</label><input id="b-rem" type="number" name="reminder_days" min="0" max="30" required value="{{ old('reminder_days', $s['reminder_days']) }}"></div>
                    <div class="field"><label for="b-sus">{{ __('Zawieszenie po terminie (dni)') }}</label><input id="b-sus" type="number" name="suspend_days" min="0" max="90" required value="{{ old('suspend_days', $s['suspend_days']) }}"></div>
                    <div class="field"><label for="b-ter">{{ __('Usunięcie po zawieszeniu (dni)') }}</label><input id="b-ter" type="number" name="terminate_days" min="1" max="365" required value="{{ old('terminate_days', $s['terminate_days']) }}"></div>
                </div>
                <label class="check-line"><input type="checkbox" name="auto_pay" value="1" @checked(old('auto_pay', $s['auto_pay']) === '1')> {{ __('Opłacaj faktury odnowień automatycznie z portfela, gdy są środki') }}</label>
            </div>
        </div>

        <div class="card" style="margin-top:16px">
            <h3 class="card-title">{{ __('Faktura') }}</h3>
            <div class="grid grid-2">
                <div class="field"><label for="b-sn">{{ __('Sprzedawca') }}</label><input id="b-sn" name="seller_name" maxlength="200" value="{{ old('seller_name', $s['seller_name']) }}"></div>
                <div class="field"><label for="b-nip">{{ __('NIP / VAT ID') }}</label><input id="b-nip" name="seller_tax_id" maxlength="50" value="{{ old('seller_tax_id', $s['seller_tax_id']) }}"></div>
                <div class="field"><label for="b-addr">{{ __('Adres') }}</label><textarea id="b-addr" name="seller_address" rows="3" class="prose-input" maxlength="500">{{ old('seller_address', $s['seller_address']) }}</textarea></div>
                <div class="field"><label for="b-notes">{{ __('Uwagi na fakturze (np. numer konta)') }}</label><textarea id="b-notes" name="invoice_notes" rows="3" class="prose-input" maxlength="1000">{{ old('invoice_notes', $s['invoice_notes']) }}</textarea></div>
                <div class="field"><label for="b-pre">{{ __('Prefiks numeru') }}</label><input id="b-pre" name="invoice_prefix" maxlength="30" required value="{{ old('invoice_prefix', $s['invoice_prefix']) }}">
                    <div class="hint">{{ __('{Y} — rok, {m} — miesiąc. Numeracja zaczyna się od 1 dla każdego prefiksu, np. FV/{Y}/ → FV/2026/0001.') }}</div></div>
            </div>
        </div>

        <button class="btn btn-primary" type="submit" style="margin-top:16px">{{ __('Zapisz') }}</button>
    </form>
@endsection
