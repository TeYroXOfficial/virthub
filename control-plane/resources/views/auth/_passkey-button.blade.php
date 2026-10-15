{{-- Przycisk WebAuthn: logowanie bez hasła, drugi składnik albo potwierdzenie tożsamości. --}}
<div class="passkey-row" data-passkey-row>
    @if ($separator ?? true) <div class="or-sep"><span>{{ __('albo') }}</span></div> @endif
    <button class="btn" type="button" style="width:100%" data-webauthn="{{ $mode }}"
            data-options="{{ $options }}" data-finish="{{ $finish }}"
            data-unsupported="{{ __('Ta przeglądarka nie obsługuje kluczy dostępu.') }}"
            data-cancelled="{{ __('Anulowano — spróbuj jeszcze raz.') }}">
        <x-icon name="key" :size="16"/> {{ $label }}
    </button>
    <div class="hint" id="webauthn-error" role="alert" style="color:var(--critical)" hidden></div>
</div>
@once
    @push('scripts')
        <script src="{{ asset('js/webauthn.js') }}?v={{ @filemtime(public_path('js/webauthn.js')) }}"></script>
    @endpush
@endonce
