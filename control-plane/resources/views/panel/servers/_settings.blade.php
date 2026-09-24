{{-- Zakładka „Ustawienia": hasło roota, reinstalacja, płyta ISO. --}}
@php
    $user = auth()->user();
    $canIso = ! $server->isContainer() && $user->can('iso', $server);
    $lastPasswordJob = $recentJobs->firstWhere('action', 'password');
@endphp

<div class="settings-list">
    @can('resetPassword', $server)
        <div class="card setting-row" id="password">
            <div class="setting-text">
                <h3><x-icon name="key" :size="16"/> Hasło roota</h3>
                <p class="muted">
                    Ustawia nowe, losowe hasło roota w działającym systemie. Zobaczysz je raz, na górze tej strony.
                    @unless ($server->isContainer())
                        W maszynie KVM musi działać <code>qemu-guest-agent</code> — nowe maszyny mają go od startu.
                    @endunless
                </p>
                @if ($lastPasswordJob && $lastPasswordJob->status === 'failed' && $lastPasswordJob->created_at->gt(now()->subHour()))
                    <p class="hint" style="color:var(--critical)">Ostatnia próba nie powiodła się: {{ $lastPasswordJob->error }}</p>
                @endif
            </div>
            <form method="POST" action="{{ route('panel.servers.password', $server) }}"
                  onsubmit="return confirm('Ustawić nowe hasło roota? Stare przestanie działać.')">
                @csrf
                <button class="btn" type="submit" @disabled(! $server->acceptsCommands() || ! $server->isRunning())
                        title="{{ $server->isRunning() ? '' : 'Uruchom maszynę, żeby zmienić hasło' }}">
                    Resetuj hasło
                </button>
            </form>
        </div>
    @endcan

    @can('rebuild', $server)
        <div class="card setting-row">
            <div class="setting-text">
                <h3><x-icon name="refresh" :size="16"/> Reinstalacja systemu</h3>
                <p class="muted">
                    Obecnie: <strong>{{ $server->template?->name ?? 'nieznany' }}</strong>.
                    Postawienie systemu od nowa kasuje dysk; adresy IP, zapora i parametry zostają.
                </p>
            </div>
            <button class="btn btn-danger" type="button" data-open-reinstall @disabled(! $server->acceptsCommands() || $osChoices->isEmpty())>
                Reinstaluj…
            </button>
        </div>
    @endcan

    @if ($canIso)
        <div class="card" id="iso">
            <h3 class="card-title"><x-icon name="disc" :size="16"/> Obraz ISO</h3>
            <dl class="kv" style="margin-bottom:14px">
                <dt>W napędzie</dt>
                <dd>{{ $server->iso?->name ?? 'brak płyty' }}</dd>
                <dt>Rozruch</dt>
                <dd>
                    @if ($server->boot_from_iso && $server->iso)
                        <span class="pill warning">z płyty</span>
                    @else
                        <span class="pill neutral">z dysku</span>
                    @endif
                </dd>
            </dl>
            <form method="POST" action="{{ route('panel.servers.iso', $server) }}">
                @csrf
                <div class="field">
                    <label for="iso-select">Płyta</label>
                    <select id="iso-select" name="iso" @disabled(! $server->acceptsCommands())>
                        <option value="">— wysuń płytę —</option>
                        @foreach ($isos as $iso)
                            <option value="{{ $iso->id }}" @selected($server->iso_image_id === $iso->id)>
                                {{ $iso->name }}@unless ($iso->is_public) (personel)@endunless
                            </option>
                        @endforeach
                    </select>
                    @if ($isos->isEmpty())
                        <div class="hint">Na węźle tej maszyny nie ma jeszcze żadnych obrazów.</div>
                    @endif
                </div>
                <div class="field">
                    <label class="check-line">
                        <input type="checkbox" name="boot" value="1" @checked($server->boot_from_iso)>
                        Uruchamiaj z płyty (przed dyskiem)
                    </label>
                    <label class="check-line">
                        <input type="checkbox" name="restart" value="1">
                        Zastosuj od razu — wyłącz i włącz maszynę
                    </label>
                </div>
                <button class="btn btn-primary" type="submit" @disabled(! $server->acceptsCommands())>Zapisz</button>
                <p class="hint" style="margin-top:10px">
                    Instalację z płyty prowadzisz w konsoli. Po instalacji wysuń płytę albo wyłącz rozruch z niej.
                    Adresy IP maszyny widzisz w zakładce Przegląd — ustaw je w instalatorze.
                </p>
            </form>
        </div>
    @endif

    <div class="card">
        <h3 class="card-title"><x-icon name="node" :size="16"/> Informacje</h3>
        <dl class="kv">
            <dt>Nazwa hosta</dt><dd class="mono">{{ $server->hostname }}</dd>
            <dt>Typ</dt><dd>{{ $server->virtualization->label() }}</dd>
            <dt>System w maszynie</dt>
            <dd>
                {{ $server->guest_os_name ?? 'jeszcze nie odczytany' }}
                @if ($server->guest_os_checked_at)
                    <span class="hint">· sprawdzony {{ $server->guest_os_checked_at->diffForHumans() }}</span>
                @endif
                <form method="POST" action="{{ route('panel.servers.detect-os', $server) }}" style="display:inline; margin:0 0 0 6px">
                    @csrf
                    <button class="btn btn-sm" type="submit" @disabled(! $server->isRunning())>Odczytaj teraz</button>
                </form>
                @unless ($server->isContainer())
                    <div class="hint">Maszyna KVM musi mieć działający <code>qemu-guest-agent</code>.</div>
                @endunless
            </dd>
            @if ($cpu = $server->hypervisor?->cpuModel())
                <dt>Procesor</dt><dd>{{ $cpu }}</dd>
            @endif
            <dt>Identyfikator</dt><dd class="mono">#{{ $server->id }}</dd>
            <dt>Utworzona</dt><dd>{{ $server->created_at->format('d.m.Y H:i') }}</dd>
        </dl>
    </div>

    @if ($user->can('destroy', $server) || $user->can('purge', $server))
        <div class="card danger-zone" id="delete">
            <h3 class="card-title" style="color:var(--critical)">Usuwanie maszyny</h3>
            @can('destroy', $server)
                <form method="POST" action="{{ route('panel.servers.destroy', $server) }}" class="setting-row"
                      onsubmit="return confirm('Usunąć {{ $server->hostname }} razem z dyskiem? Tej operacji nie da się cofnąć.')">
                    @csrf @method('DELETE')
                    <div class="setting-text">
                        <strong>Usuń maszynę</strong>
                        <p class="muted">Kasuje maszynę i jej dysk na węźle, potem zwalnia adresy IP.</p>
                        <label class="check-line" style="margin-top:8px">
                            <input type="checkbox" name="confirm" value="1" required>
                            Rozumiem, że dane zostaną bezpowrotnie usunięte
                        </label>
                    </div>
                    <button class="btn btn-danger-solid" type="submit" @disabled($server->state === \App\Enums\ServerState::Deleting)>Usuń maszynę</button>
                </form>
            @endcan
            @can('purge', $server)
                <form method="POST" action="{{ route('panel.servers.purge', $server) }}" class="setting-row" style="margin-top:16px; padding-top:16px; border-top:1px solid var(--border)"
                      onsubmit="return confirm('Usunąć wpis TYLKO z panelu? Panel nie skontaktuje się z węzłem.')">
                    @csrf
                    <div class="setting-text">
                        <strong>Usuń tylko z panelu</strong> <span class="pill neutral">administrator</span>
                        <p class="muted">
                            Bez kontaktu z węzłem — gdy węzeł nie istnieje albo nie odpowiada, maszyny już tam nie ma
                            albo operacja utknęła. Zwalnia adresy IP i zasoby węzła. Jeśli maszyna jednak działa na węźle,
                            trzeba ją skasować tam ręcznie.
                        </p>
                        <label class="check-line" style="margin-top:8px">
                            <input type="checkbox" name="confirm" value="1" required>
                            Potwierdzam usunięcie wpisu
                        </label>
                    </div>
                    <button class="btn" type="submit">Usuń z panelu</button>
                </form>
            @endcan
        </div>
    @endif
</div>
