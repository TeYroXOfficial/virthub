{{-- Reinstalacja: wybór systemu w oknie modalnym, otwieranym przyciskiem obok zasilania. --}}
<dialog class="modal" id="reinstall" aria-labelledby="reinstall-title" @if($errors->hasAny(['template', 'confirm', 'ssh_key'])) data-open @endif>
    <form method="POST" action="{{ route('panel.servers.rebuild', $server) }}" class="modal-body" data-reinstall-form>
        @csrf
        <header class="modal-head">
            <div>
                <h2 id="reinstall-title">Reinstalacja systemu</h2>
                <p class="muted">Wybierz nowy system dla <strong>{{ $server->hostname }}</strong>.</p>
            </div>
            <button type="button" class="icon-btn" data-close aria-label="Zamknij">✕</button>
        </header>

        @if ($errors->hasAny(['template', 'confirm', 'ssh_key']))
            <div class="alert alert-error"><div>{{ $errors->first('template') ?: $errors->first('confirm') ?: $errors->first('ssh_key') }}</div></div>
        @endif

        <div class="os-grid" role="radiogroup" aria-label="System operacyjny">
            @foreach ($templates as $t)
                <label class="os-card">
                    <input type="radio" name="template" value="{{ $t->id }}" required
                           @checked((int) old('template', $server->os_template_id) === $t->id)>
                    @include('panel.servers._os-badge', ['template' => $t])
                    <span class="os-name">{{ $t->name }}</span>
                    @if ($t->id === $server->os_template_id)
                        <span class="os-current">obecny</span>
                    @endif
                </label>
            @endforeach
        </div>

        <details class="modal-more" @if(old('ssh_key')) open @endif>
            <summary>Dodaj klucz SSH (opcjonalnie)</summary>
            <textarea name="ssh_key" rows="2" placeholder="ssh-ed25519 AAAA… twoj@komputer">{{ old('ssh_key') }}</textarea>
            <div class="hint">Hasło roota wygenerujemy zawsze i pokażemy po zakończeniu.</div>
        </details>

        <label class="confirm-line">
            <input type="checkbox" name="confirm" value="1" required>
            <span>Rozumiem, że <strong>wszystkie dane na dysku zostaną usunięte</strong>. Adresy IP i zapora zostają.</span>
        </label>

        <footer class="modal-foot">
            <button type="button" class="btn" data-close>Anuluj</button>
            <button type="submit" class="btn btn-danger-solid">
                <x-icon name="refresh" :size="15"/> Reinstaluj serwer
            </button>
        </footer>
    </form>
</dialog>
