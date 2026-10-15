{{-- Tryb ratunkowy i nazwa hosta. --}}
@php $rescueTemplate = $server->isContainer() ? null : \App\Domain\Provisioning\RescueMode::template(); @endphp

@if (! $server->isContainer() && auth()->user()->can('rebuild', $server))
    <div class="card" id="rescue">
        <h3 class="card-title"><x-icon name="shield" :size="16"/> {{ __('Tryb ratunkowy') }}
            @if ($server->inRescue()) <span class="pill warning">{{ __('aktywny od :time', ['time' => $server->rescue_at->diffForHumans()]) }}</span> @endif</h3>
        @error('guest') <div class="hint" style="color:var(--critical)">{{ $message }}</div> @enderror
        @if ($server->inRescue())
            <dl class="kv">
                <dt>{{ __('System') }}</dt><dd>{{ $rescueTemplate?->name ?? __('ratunkowy') }}</dd>
                <dt>{{ __('Logowanie') }}</dt><dd><code>ssh root@{{ $server->primaryIp()?->address ?? '…' }}</code> {{ __('albo konsola') }}</dd>
                <dt>{{ __('Hasło roota') }}</dt><dd><code data-copy>{{ $server->rescue_password }}</code></dd>
                <dt>{{ __('Dysk maszyny') }}</dt><dd><code>/dev/vdi</code> — {{ __('np. mount /dev/vdi1 /mnt') }}</dd>
            </dl>
            <form method="POST" action="{{ route('panel.servers.rescue.stop', $server) }}" data-confirm="{{ __('Zakończyć tryb ratunkowy? Maszyna uruchomi się z własnego dysku.') }}">
                @csrf @method('DELETE')
                <button class="btn btn-primary" type="submit" @disabled($server->state->isTransitioning())>{{ __('Zakończ tryb ratunkowy') }}</button>
            </form>
        @elseif ($rescueTemplate)
            <p class="muted">{{ __('Uruchamia maszynę z czystego systemu (:name) z tymi samymi adresami IP. Twój dysk jest podłączony jako /dev/vdi — możesz naprawić system, odzyskać pliki albo zresetować hasło. Dane na dysku nie są zmieniane, dopóki sam ich nie zmienisz.', ['name' => $rescueTemplate->name]) }}</p>
            <form method="POST" action="{{ route('panel.servers.rescue.start', $server) }}" data-confirm="{{ __('Uruchomić tryb ratunkowy? Maszyna zostanie wyłączona i uruchomiona z systemu ratunkowego.') }}">
                @csrf
                <div class="field"><label for="rescue-key">{{ __('Klucz SSH') }} <span class="muted">{{ __('(opcjonalnie)') }}</span></label>
                    <textarea id="rescue-key" name="ssh_key" rows="2" placeholder="ssh-ed25519 AAAA…"></textarea></div>
                <button class="btn" type="submit" @disabled(! $server->acceptsCommands())>{{ __('Uruchom tryb ratunkowy') }}</button>
            </form>
        @else
            <p class="muted">{{ __('Tryb ratunkowy jest niedostępny — administrator nie udostępnił jeszcze systemu ratunkowego.') }}</p>
        @endif
    </div>
@endif

@can('power', $server)
    <div class="card" id="hostname">
        <h3 class="card-title"><x-icon name="node" :size="16"/> {{ __('Nazwa hosta') }}</h3>
        <form method="POST" action="{{ route('panel.servers.hostname', $server) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            @csrf @method('PUT')
            <input name="hostname" value="{{ old('hostname', $server->hostname) }}" maxlength="253" required style="flex:1;min-width:220px" aria-label="{{ __('Nazwa hosta') }}">
            <button class="btn" type="submit">{{ __('Zmień') }}</button>
        </form>
        <p class="hint">{{ __('Zmienia nazwę w panelu, a w działającym Linuksie z qemu-guest-agent także w systemie. W pozostałych przypadkach system dostanie ją przy reinstalacji.') }}</p>
    </div>
@endcan
