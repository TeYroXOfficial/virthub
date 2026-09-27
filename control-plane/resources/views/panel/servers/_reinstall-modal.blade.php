{{-- Reinstalacja: wybór systemu w oknie modalnym, otwieranym przyciskiem obok zasilania. --}}
<dialog class="modal" id="reinstall" aria-labelledby="reinstall-title" @if($errors->hasAny(['template', 'confirm', 'ssh_key'])) data-open @endif>
    <form method="POST" action="{{ route('panel.servers.rebuild', $server) }}" class="modal-body" data-reinstall-form>
        @csrf
        <header class="modal-head">
            <div>
                <h2 id="reinstall-title">{{ __('Reinstalacja systemu') }}</h2>
                <p class="muted">{{ __('Wybierz nowy system dla') }} <strong>{{ $server->hostname }}</strong>.</p>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="{{ __('Zamknij') }}">✕</button>
        </header>

        @if ($errors->hasAny(['template', 'confirm', 'ssh_key']))
            <div class="alert alert-error"><div>{{ $errors->first('template') ?: $errors->first('confirm') ?: $errors->first('ssh_key') }}</div></div>
        @endif

        @include('panel.servers._os-picker', ['selected' => old('template', $server->os_template_id), 'current' => $server->os_template_id])

        <details class="modal-more" @if(old('ssh_key')) open @endif>
            <summary>{{ __('Dodaj klucz SSH (opcjonalnie)') }}</summary>
            <textarea name="ssh_key" rows="2" placeholder="ssh-ed25519 AAAA… twoj@komputer">{{ old('ssh_key') }}</textarea>
            <div class="hint">{{ __('Hasło roota wygenerujemy zawsze i pokażemy po zakończeniu.') }}</div>
        </details>

        <label class="confirm-line">
            <input type="checkbox" name="confirm" value="1" required>
            <span>{{ __('Rozumiem, że') }} <strong>{{ __('wszystkie dane na dysku zostaną usunięte') }}</strong>{{ __('. Adresy IP i zapora zostają.') }}</span>
        </label>

        <footer class="modal-foot">
            <button type="button" class="btn" data-close>{{ __('Anuluj') }}</button>
            <button type="submit" class="btn btn-danger-solid">
                <x-icon name="refresh" :size="15"/> {{ __('Reinstaluj serwer') }}
            </button>
        </footer>
    </form>
</dialog>
